"""
Machine Learning Model for Sales Forecasting and Risk Prediction.

Trains Random Forest models on product sales data to predict future sales,
calculate risk scores, and recommend optimal discount strategies.
"""

import logging
import os
import pickle
from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.preprocessing import StandardScaler

try:
    from .data_service import DataProcessor, DatabaseConnector
    from .ml_config import (
        CONFIDENCE_LEVELS,
        FEATURE_CONFIG,
        FORECAST_FEATURE_LABELS,
        FORECAST_FEATURE_NAMES,
        ML_CONFIG,
        MODEL_PATHS,
        RISK_LEVELS,
        RECOMMENDATION_POLICY,
        STRATEGIES,
        THRESHOLDS,
    )
except ImportError:
    from data_service import DataProcessor, DatabaseConnector
    from ml_config import (
        CONFIDENCE_LEVELS,
        FEATURE_CONFIG,
        FORECAST_FEATURE_LABELS,
        FORECAST_FEATURE_NAMES,
        ML_CONFIG,
        MODEL_PATHS,
        RISK_LEVELS,
        RECOMMENDATION_POLICY,
        STRATEGIES,
        THRESHOLDS,
    )

logger = logging.getLogger(__name__)

# Confidence of an estimate that is not the model's (past sales or a rough guess);
# also the lowest a model forecast can score. See get_prediction_confidence().
NO_FORECAST_CONFIDENCE = 0.5

_MARGIN_REVIEW_ADVISORY = {
    "strategy_id": "price_cost_review",
    "strategy_name": "Price and Cost Review Required",
    "priority": 0,
    "fit_score": 2.0,
    "recommended_discount": 0,
    "duration_days": 1,
    "expected_impact_min": 0,
    "expected_impact_max": 0,
    "implementation_steps": [
        "Verify that the recorded selling price and unit cost are correct.",
        "Do not apply another discount while the selling price is at or below cost.",
        "Review supplier cost, target margin, and a sustainable regular price.",
        "If the item must be liquidated, record the expected loss and obtain owner approval.",
    ],
    "why_it_works": (
        "The current margin cannot support the minimum discount in the available strategies. "
        "Correcting the price or cost first prevents a promotion from increasing the loss."
    ),
    "risk_level_target": "ALL",
    "is_escalation": True,
}

_EXPIRED_STOCK_ADVISORY = {
    "strategy_id": "expired_stock_removal",
    "strategy_name": "Expired Stock Removal Required",
    "priority": 0,
    "fit_score": 3.0,
    "recommended_discount": 0,
    "duration_days": 1,
    "expected_impact_min": 0,
    "expected_impact_max": 0,
    "implementation_steps": [
        "Remove the expired batch from sale immediately.",
        "Verify the affected quantity using the product batch records.",
        "Record the stock adjustment and disposal according to store policy.",
        "Review replenishment quantities to reduce future expiry losses.",
    ],
    "why_it_works": "Expired inventory must not be promoted or included in sales forecasts.",
    "risk_level_target": "CRITICAL",
    "is_escalation": True,
}


class RecommendationModel:
    """Machine Learning model for product sales forecasting and recommendations."""

    def __init__(self):
        """Initialize model and load pre-trained weights if available."""
        self.model: Optional[RandomForestRegressor] = None
        self.scaler: Optional[StandardScaler] = None
        self.feature_names: Optional[List[str]] = None
        self.model_metrics: Dict[str, Any] = {}
        # (checked at, answer) of forecast_in_use(), so a page of products asks the database once
        self._records_check: Optional[tuple] = None
        self.load_model()

    # ========================================================================
    # MODEL LOADING AND TRAINING
    # ========================================================================

    def load_model(self) -> None:
        """
        Load the saved model and scaler from disk.

        A file saved before the forecast inputs changed is not used. That includes
        the earlier model, which was given the figure it had to predict: it is
        replaced by one trained from the sales history. When there is not enough
        history to train on, the model stays empty and the recommendation rules
        work from past sales instead.
        """
        try:
            if (
                os.path.exists(MODEL_PATHS["model"])
                and os.path.exists(MODEL_PATHS["scaler"])
            ):
                with open(MODEL_PATHS["scaler"], "rb") as f:
                    scaler = pickle.load(f)
                with open(MODEL_PATHS["model"], "rb") as f:
                    model = pickle.load(f)
                if getattr(model, "forecast_feature_names_", None) == FORECAST_FEATURE_NAMES:
                    self.model, self.scaler = model, scaler
                    self.feature_names = list(FORECAST_FEATURE_NAMES)
                    self.model_metrics = dict(getattr(model, "forecast_metrics_", None) or {})
                    logger.info("Model loaded successfully")
                    return
                logger.warning("Saved model predates the current forecast inputs. Retraining.")
            else:
                logger.warning("No pre-trained model found. Training new model.")
        except Exception as e:
            logger.error(f"Error loading model: {e}")

        self.model, self.scaler = None, None
        self.train_model()

    def train_model(self) -> bool:
        """
        Train the sales forecast and score it on a month it has never seen.

        The examples come from DataProcessor.build_forecast_training_set(): a product
        on a reference day in the past, with its sales in the 60 days before that day
        as inputs and the units it sold in the 30 days after it as the answer. The
        answer always lies after the inputs, so the model is never handed the figure
        it is asked to predict.

        The score is a time-based holdout, the way a forecast is really used. The most
        recent reference day is set aside; a model is fitted only on examples whose 30
        answer days ended before it; its predictions for the held-out month are
        compared with what was actually sold. The same month is also "forecast" by
        repeating each product's previous 30 days, so the score can be read against a
        guess that needs no model at all.

        The model that is saved is then fitted on every example.

        Returns:
            bool: True if training successful, False otherwise (the model in use is
            left as it was)
        """
        try:
            logger.info("Starting model training...")

            samples = DataProcessor.build_forecast_training_set()
            if samples.empty:
                logger.warning("No usable sales history to train the forecast on")
                return False

            horizon = timedelta(days=FEATURE_CONFIG["forecast_horizon"])
            test_ref = samples["ref"].max()
            test = samples[samples["ref"] == test_ref]
            train = samples[samples["ref"].apply(lambda ref: ref + horizon <= test_ref)]

            if len(train) < FEATURE_CONFIG["min_training_samples"] or test.empty:
                logger.warning(
                    f"Insufficient training data: {len(train)} examples before the held-out month "
                    f"(about four months of unbroken sales history are needed)"
                )
                return False

            rf_params = {k: v for k, v in ML_CONFIG.items() if k != "model_type"}

            def inputs(frame: pd.DataFrame) -> np.ndarray:
                return frame[FORECAST_FEATURE_NAMES].to_numpy(dtype=float)

            def fit(frame: pd.DataFrame):
                scaler = StandardScaler()
                model = RandomForestRegressor(**rf_params)
                model.fit(scaler.fit_transform(inputs(frame)), frame["target"].to_numpy(dtype=float))
                return model, scaler

            def scores(actual: np.ndarray, predicted: np.ndarray) -> Dict[str, float]:
                mse = float(mean_squared_error(actual, predicted))
                return {
                    "mse": mse,
                    "rmse": float(np.sqrt(mse)),
                    "mae": float(mean_absolute_error(actual, predicted)),
                    # R2 is undefined when every product sold the same amount
                    "r2": float(r2_score(actual, predicted)) if np.var(actual) > 0 else 0.0,
                }

            # 1. Score on the held-out month
            eval_model, eval_scaler = fit(train)
            y_test = test["target"].to_numpy(dtype=float)
            model_scores = scores(y_test, np.maximum(eval_model.predict(eval_scaler.transform(inputs(test))), 0))
            naive_scores = scores(y_test, test["units_last_30"].to_numpy(dtype=float))

            metrics = {
                "mse": model_scores["mse"],
                "rmse": model_scores["rmse"],
                "mae": model_scores["mae"],
                "r2_score": model_scores["r2"],
                # "Next 30 days = previous 30 days", scored on the same month
                "baseline_mae": naive_scores["mae"],
                "baseline_rmse": naive_scores["rmse"],
                "baseline_r2": naive_scores["r2"],
                "evaluation": "time_holdout",
                "evaluation_train_samples": int(len(train)),
                "test_samples": int(len(test)),
                "test_period_start": str(test_ref),
                "test_period_end": str(test_ref + horizon - timedelta(days=1)),
                "reference_days": int(samples["ref"].nunique()),
                "training_samples": int(len(samples)),
                "training_date": datetime.now().isoformat(),
                "feature_count": len(FORECAST_FEATURE_NAMES),
            }

            # 2. The model that is used learns from every example, the held-out month included
            model, scaler = fit(samples)
            # Saved with the model, so a file is recognised (and its score shown) after a restart
            model.forecast_feature_names_ = list(FORECAST_FEATURE_NAMES)
            model.forecast_metrics_ = dict(metrics)

            os.makedirs(MODEL_PATHS["models_dir"], exist_ok=True)
            with open(MODEL_PATHS["scaler"], "wb") as f:
                pickle.dump(scaler, f)
            with open(MODEL_PATHS["model"], "wb") as f:
                pickle.dump(model, f)

            self.model, self.scaler = model, scaler
            self.feature_names = list(FORECAST_FEATURE_NAMES)
            self.model_metrics = metrics

            logger.info(
                f"Model trained successfully on {len(samples)} examples. Held-out month "
                f"{metrics['test_period_start']} to {metrics['test_period_end']}: "
                f"R2 = {metrics['r2_score']:.4f}, MAE = {metrics['mae']:.2f} units "
                f"(repeating last month: R2 = {metrics['baseline_r2']:.4f}, MAE = {metrics['baseline_mae']:.2f})"
            )
            self._save_metrics()
            return True

        except Exception as e:
            logger.error(f"Error during model training: {e}")
            return False

    # ========================================================================
    # PREDICTION METHODS
    # ========================================================================

    def forecast_in_use(self) -> bool:
        """
        Whether forecasts are being made right now: the model is trained and the store
        has 60 days of unbroken sales records to forecast from (the kind of period the
        model was trained on). The answer is kept for a minute.
        """
        if self.model is None or self.scaler is None:
            return False
        now = datetime.now()
        if self._records_check is None or (now - self._records_check[0]).total_seconds() > 60:
            self._records_check = (now, DataProcessor.recent_records_complete())
        return self._records_check[1]

    def forecast_units(self, forecast_features: Optional[Dict[str, float]]) -> Optional[float]:
        """
        Units a product is expected to sell in the next 30 days.

        Args:
            forecast_features: the product's inputs from DataProcessor.forecast_features()

        Returns:
            The forecast, or None when none can be made: forecasts are not in use
            (see forecast_in_use), the product is too new to have inputs, or it had
            no sale at all in the 60-day window. The model has only ever seen products
            that were selling, so it has nothing to go on for one that is not.
        """
        if not self._can_forecast(forecast_features):
            return None
        X = np.array([[forecast_features[name] for name in FORECAST_FEATURE_NAMES]], dtype=float)
        prediction = self.predict_sales(X)
        return float(prediction[0]) if prediction is not None else None

    def _can_forecast(self, forecast_features: Optional[Dict[str, float]]) -> bool:
        """Whether a forecast is made for a product with these inputs (see forecast_units)."""
        if not forecast_features or not self.forecast_in_use():
            return False
        return bool(forecast_features.get("sale_days_60"))

    def predict_sales(self, X: np.ndarray) -> Optional[np.ndarray]:
        """
        Predict units sold in the next 30 days.

        Args:
            X: Feature matrix, columns in FORECAST_FEATURE_NAMES order (unscaled)

        Returns:
            Array of predictions or None on error
        """
        if self.model is None or self.scaler is None:
            logger.error("Model not trained")
            return None

        try:
            if len(X.shape) == 1:
                X = X.reshape(1, -1)

            X_scaled = self.scaler.transform(X)
            predictions = self.model.predict(X_scaled)
            return np.maximum(predictions, 0)  # Ensure non-negative
        except Exception as e:
            logger.error(f"Error during prediction: {e}")
            return None

    def get_prediction_confidence(self, forecast_features: Optional[Dict[str, float]]) -> float:
        """
        How far a product's sales estimate can be trusted (0-1 scale).

        The forest is 100 trees, each giving its own forecast; the forecast shown is
        their average. Confidence is how closely the trees agree: 1 minus their spread
        (standard deviation) relative to the forecast, kept between 0.50 and 0.95.
        Trees that agree on 20 units give a high score; trees split between 5 and 40
        give a low one. On held-out months the forecasts the trees agreed on were the
        ones that missed least.

        A product with no forecast (see forecast_units) gets the lowest score, 0.50:
        its estimate is an average of past sales or a rough guess, not the model's.

        Args:
            forecast_features: the product's inputs from DataProcessor.forecast_features()

        Returns:
            float: Confidence score between 0.50 and 0.95
        """
        try:
            if not self._can_forecast(forecast_features):
                return NO_FORECAST_CONFIDENCE
            X = np.array([[forecast_features[name] for name in FORECAST_FEATURE_NAMES]], dtype=float)
            X_scaled = self.scaler.transform(X)
            by_tree = np.array([tree.predict(X_scaled)[0] for tree in self.model.estimators_])
            # + 1 unit: a forecast of half a unit, give or take half a unit, is not a wild guess
            spread = float(by_tree.std()) / (max(float(by_tree.mean()), 0.0) + 1.0)
            return float(min(0.95, max(NO_FORECAST_CONFIDENCE, 1.0 - spread)))
        except Exception as e:
            logger.error(f"Error calculating confidence: {e}")
            return NO_FORECAST_CONFIDENCE

    # ========================================================================
    # RISK ASSESSMENT METHODS
    # ========================================================================

    def calculate_risk_score(
        self, product_id: int, product_data: Dict[str, Any], features: Dict[str, Any]
    ) -> float:
        """
        Calculate overall risk score (0-1) for a product.

        Combines: expiry urgency, sales velocity, and stock level.

        Args:
            product_id: Product ID
            product_data: Product information
            features: Extracted features for product

        Returns:
            float: Risk score between 0 and 1
        """
        try:
            # Normalize factors to 0-1
            days_expiry = features["days_until_expiry"]
            expiry_factor = max(0, min(1, 1 - (days_expiry / 30)))

            monthly_velocity = features["monthly_sales_velocity"]
            velocity_factor = (
                max(0, 1 - (monthly_velocity / 100))
                if monthly_velocity < 100
                else 0
            )

            current_stock = features["current_stock"]
            stock_factor = min(1, current_stock / 100) if current_stock > 0 else 1

            # Weighted score
            risk_score = (
                expiry_factor * 0.4  # Expiry is most critical
                + velocity_factor * 0.4  # Slow-moving items
                + stock_factor * 0.2  # High stock levels
            )

            return float(min(1, max(0, risk_score)))
        except Exception as e:
            logger.error(f"Error calculating risk score: {e}")
            return 0.5

    def determine_risk_level(self, risk_score: float, days_in_stock: int = 0, days_until_expiry: int = 999) -> str:
        """
        Determine risk level based on strict categorical rules.
        Priority:
        1. CRITICAL: days_in_stock >= 90 OR days_until_expiry <= 14
        2. WARNING: days_in_stock >= 50
        3. MONITOR: days_in_stock < 50
        """
        # 1. CRITICAL: 90+ days or near expiry
        if days_in_stock >= THRESHOLDS["days_in_stock_critical"] or (0 <= days_until_expiry <= THRESHOLDS["critical_stock_days"]):
            return "CRITICAL"
        
        # 2. WARNING: 50–89 days
        if days_in_stock >= THRESHOLDS["days_in_stock_warning"]:
            return "WARNING"
            
        # 3. MONITOR: 1–49 days
        return "MONITOR" if days_in_stock > 0 else "LOW"

    # ========================================================================
    # STRATEGY RECOMMENDATION METHODS
    # ========================================================================

    def recommend_strategy(
        self,
        product_id: int,
        product_data: Dict[str, Any],
        features: Dict[str, Any],
        risk_score: float,
        risk_level: str = None,
        used_strategy_ids: Optional[List[str]] = None,
    ) -> List[Dict[str, Any]]:
        """
        Recommend best marketing strategies for product based on its risk level.

        Filters strategies by the product's risk level and observable conditions,
        then excludes any strategy already applied within the no-repeat window, and
        finally ranks by condition fit score. Returns up to 3 strategies.

        Args:
            used_strategy_ids: strategy IDs already applied within the no-repeat lookback
                window.  These are excluded so the engine never cycles the same advice.
                When all applicable strategies are exhausted, an escalation advisory is
                returned instead.
        """
        if used_strategy_ids is None:
            used_strategy_ids = []

        if features.get("is_expired"):
            return [_EXPIRED_STOCK_ADVISORY.copy()]

        _ESCALATION_ADVISORY = {
            "strategy_id": "escalate_reevaluate",
            "strategy_name": "Escalate & Re-evaluate",
            "priority": 99,
            "fit_score": 0.1,
            "recommended_discount": 0,
            "duration_days": 14,
            "expected_impact_min": 0,
            "expected_impact_max": 10,
            "implementation_steps": [
                "All standard promotional strategies have already been tried for this product recently.",
                "Review whether the current selling price is still competitive in the market.",
                "Consider negotiating better terms with the supplier or switching to a different supplier.",
                "Evaluate whether this product should be bundled with a higher-demand item permanently.",
                "If stock levels are still high, consult with the owner about returning or liquidating remaining units.",
                "Update the product's cost price and selling price in the system if conditions have changed.",
            ],
            "why_it_works": (
                "When repeated promotions have not resolved a slow-moving or high-risk product, "
                "the next step is a deeper business review — pricing strategy, supplier relationship, "
                "or product discontinuation. This prevents an endless cycle of discounts."
            ),
            "risk_level_target": "ALL",
            "is_escalation": True,
        }

        recommended = []

        try:
            # Determine risk_level if not provided
            if risk_level is None:
                risk_level = self.determine_risk_level(risk_score)

            is_expiring_soon = features["is_critical_expiry"]
            is_slow_moving = (
                features["monthly_sales_velocity"]
                < RECOMMENDATION_POLICY["monthly_sales_below"]
            )
            is_high_stock = features["current_stock"] > 20
            is_moderate_stock = 5 <= features["current_stock"] <= 20
            bogo_margin_safe = features["markup_percentage"] >= 100
            unit_price = float(features.get("unit_price", 0) or 0)
            unit_cost = float(features.get("cost_price", 0) or 0)
            max_non_loss_discount = (
                ((unit_price - unit_cost) / unit_price) * 100
                if unit_price > unit_cost and unit_price > 0
                else 0
            )
            unsafe_discount_skipped = False

            unit_str = str(product_data.get("unit") or "packaged").lower()
            is_packaged = not ("kilo" in unit_str or "gram" in unit_str)

            # Try loading strategies from DB first
            db_strategies = self._load_db_strategies()
            strategies_to_use = db_strategies if db_strategies else STRATEGIES

            # ── Rule 1: Filter by risk_level ─────────────────────────────────────
            compatible_risk_levels = {
                "CRITICAL": {"CRITICAL", "WARNING"},
                "WARNING": {"WARNING"},
                "MONITOR": {"MONITOR"},
                "LOW": {"MONITOR"},
            }.get(risk_level, {risk_level})

            filtered_strategies = []
            for strategy in strategies_to_use:
                strategy_risk_levels = strategy.get("risk_levels", [])
                if strategy_risk_levels:
                    if compatible_risk_levels.intersection(strategy_risk_levels):
                        filtered_strategies.append(strategy)
                else:
                    filtered_strategies.append(strategy)

            if not filtered_strategies:
                filtered_strategies = strategies_to_use

            # ── Rule 2: Exclude recently-tried strategies (no-repeat window) ─────
            used_set = set(str(sid) for sid in used_strategy_ids)
            fresh_strategies = [s for s in filtered_strategies if str(s["id"]) not in used_set]

            all_exhausted = len(fresh_strategies) == 0
            strategies_to_score = fresh_strategies if not all_exhausted else filtered_strategies

            for strategy in strategies_to_score:
                conditions = strategy.get("conditions", [])
                observable_matches = {
                    "critical_expiry": is_expiring_soon,
                    "slow_moving": is_slow_moving,
                    "high_stock": is_high_stock,
                    "moderate_stock": is_moderate_stock,
                    "packaged": is_packaged,
                    "low_priority": risk_level in ("MONITOR", "LOW"),
                }
                required = [c for c in conditions if c in observable_matches]

                # Treat observable conditions as requirements. The previous
                # partial-match scoring let BOGO win on "packaged + slow" even
                # when an item was not near expiry.
                if any(not observable_matches[c] for c in required):
                    continue
                # Buy 1 Take 1 gives every second unit free: it needs a healthy margin and a
                # product counted in whole units (not one sold by the kilo or gram)
                if str(strategy.get("id")) == "buy_one_take_one" and (not bogo_margin_safe or not is_packaged):
                    continue

                condition_weights = {
                    "critical_expiry": 0.35,
                    "high_stock": 0.25,
                    "moderate_stock": 0.20,
                    "slow_moving": 0.10,
                    "packaged": 0.05,
                    "low_priority": 0.10,
                }
                fit_score = 1.0 + sum(condition_weights.get(c, 0.05) for c in required)
                fit_score += 0.05 * sum(
                    1 for c in conditions if c in ("complementary_products", "seasonal")
                )

                if required or conditions:
                    d_range = strategy.get("discount_range", (0, 10))
                    if isinstance(d_range, (list, tuple)):
                        discount_min, discount_max = d_range
                    else:
                        discount_min = float(strategy.get("discount_min", 0))
                        discount_max = float(strategy.get("discount_max", 10))

                    if not is_expiring_soon and discount_min > max_non_loss_discount:
                        unsafe_discount_skipped = True
                        continue

                    margin = features["markup_percentage"]
                    recommended_discount = min(
                        discount_max, max(discount_min, margin * 0.2)
                    )
                    if not is_expiring_soon:
                        recommended_discount = min(recommended_discount, max_non_loss_discount)

                    exp_impact = strategy.get("expected_impact", (0, 0))
                    if isinstance(exp_impact, (list, tuple)):
                        impact_min, impact_max = exp_impact
                    else:
                        impact_min = strategy.get("expected_impact_min", 0)
                        impact_max = strategy.get("expected_impact_max", 0)

                    recommended.append(
                        {
                            "strategy_id": str(strategy["id"]),
                            "strategy_name": strategy["name"],
                            "priority": strategy.get("priority", 1),
                            "fit_score": float(fit_score),
                            "recommended_discount": float(recommended_discount),
                            "duration_days": strategy.get("duration_days", 7),
                            "expected_impact_min": int(impact_min),
                            "expected_impact_max": int(impact_max),
                            "implementation_steps": strategy.get("implementation_steps", []),
                            "why_it_works": strategy.get("why_it_works", ""),
                            "risk_level_target": risk_level,
                            # Tag previously-used strategies so UI can warn the user
                            "previously_used": str(strategy["id"]) in used_set,
                        }
                    )

            # Stable tie-breaking keeps the displayed strategy order and its
            # configured priority consistent across refreshes.
            recommended.sort(key=lambda x: (-x["fit_score"], x["priority"], x["strategy_id"]))
            result = recommended[:3]

            if not result and unsafe_discount_skipped and not is_expiring_soon:
                return [_MARGIN_REVIEW_ADVISORY.copy()]

            # ── Rule 3: Escalation when all fresh strategies are exhausted ────────
            if all_exhausted:
                logger.info(
                    f"All strategies already tried recently for product {product_id} "
                    f"(used={used_strategy_ids}). Returning escalation advisory."
                )
                return [_ESCALATION_ADVISORY]

            # For MONITOR products, add monitoring message if no strong strategies
            if risk_level == "MONITOR":
                for s in result:
                    s["monitor_message"] = (
                        "This item is selling normally. Monitor it weekly and "
                        "revisit if sales decline for 2+ consecutive weeks."
                    )
                if not result:
                    result = [{
                        "strategy_id": "monitor_advisory",
                        "strategy_name": "Weekly Monitoring",
                        "priority": 10,
                        "fit_score": 0.3,
                        "recommended_discount": 0,
                        "duration_days": 7,
                        "expected_impact_min": 0,
                        "expected_impact_max": 5,
                        "implementation_steps": [
                            "Check weekly sales trends for this product",
                            "Compare current week vs. previous week sales",
                            "No action needed unless sales drop for 2+ weeks",
                            "If declining, consider a mild promotional strategy",
                            "Update inventory records if stock changes",
                        ],
                        "why_it_works": "Regular monitoring catches early signs of decline before they become critical, allowing proactive rather than reactive management.",
                        "risk_level_target": "MONITOR",
                        "monitor_message": (
                            "This item is selling normally. Monitor it weekly and "
                            "revisit if sales decline for 2+ consecutive weeks."
                        ),
                    }]

            return result

        except Exception as e:
            logger.error(f"Error recommending strategy: {e}")
            return []

    @staticmethod
    def _load_db_strategies() -> List[Dict[str, Any]]:
        """Load strategy templates from the database."""
        try:
            import json
            rows = DatabaseConnector.execute_query(
                "SELECT * FROM strategy_templates WHERE is_active = 1 ORDER BY priority ASC"
            )
            if not rows:
                return []

            strategies = []
            for row in rows:
                conditions = row.get("conditions") or "[]"
                steps = row.get("implementation_steps") or "[]"
                if isinstance(conditions, str):
                    conditions = json.loads(conditions)
                if isinstance(steps, str):
                    steps = json.loads(steps)

                # Parse risk_levels JSON
                risk_levels_raw = row.get("risk_levels") or "[]"
                if isinstance(risk_levels_raw, str):
                    risk_levels = json.loads(risk_levels_raw)
                else:
                    risk_levels = risk_levels_raw if isinstance(risk_levels_raw, list) else []

                strategies.append({
                    "id": row["id"],
                    "name": row["name"],
                    "priority": int(row["priority"]),
                    "discount_range": (float(row["discount_min"]), float(row["discount_max"])),
                    "duration_days": int(row["duration_days"]),
                    "conditions": conditions,
                    "risk_levels": risk_levels,
                    "expected_impact": (int(row["expected_impact_min"]), int(row["expected_impact_max"])),
                    "implementation_steps": steps,
                    "why_it_works": row.get("why_it_works") or "",
                })
            return strategies
        except Exception as e:
            logger.warning(f"Could not load strategies from DB: {e}")
            return []

    # ========================================================================
    # UTILITY METHODS
    # ========================================================================

    def _save_metrics(self) -> None:
        """Save model metrics to database for tracking."""
        try:
            query = """
                INSERT INTO ml_model_metrics (metric_name, metric_value, created_at)
                VALUES (%s, %s, NOW())
            """
            metrics_data = [
                ("mse", str(self.model_metrics["mse"])),
                ("rmse", str(self.model_metrics["rmse"])),
                ("mae", str(self.model_metrics["mae"])),
                ("r2_score", str(self.model_metrics["r2_score"])),
                ("training_samples", str(self.model_metrics["training_samples"])),
                ("test_samples", str(self.model_metrics["test_samples"])),
                ("baseline_mae", str(self.model_metrics["baseline_mae"])),
                ("baseline_r2", str(self.model_metrics["baseline_r2"])),
            ]
            DatabaseConnector.execute_batch_insert(query, metrics_data)
        except Exception as e:
            logger.warning(f"Could not save metrics to DB: {e}")

    def feature_importances(self) -> List[Dict[str, Any]]:
        """
        How much the trained forest relies on each input, largest first.

        These are the forest's own feature importances: the share of its error
        reduction that came from splitting on each input, over all trees. They add up
        to 1 and describe the model as a whole, not any single product's forecast.
        Empty when the model is not trained.
        """
        if self.model is None:
            return []
        weights = [
            {
                "name": name,
                "label": FORECAST_FEATURE_LABELS.get(name, name),
                "importance": float(weight),
            }
            for name, weight in zip(FORECAST_FEATURE_NAMES, self.model.feature_importances_)
        ]
        return sorted(weights, key=lambda w: -w["importance"])

    def get_model_info(self) -> Dict[str, Any]:
        """
        Get information about current model.

        Returns:
            Dict with model type, training status, metrics, and parameters
        """
        return {
            "model_type": ML_CONFIG["model_type"],
            "is_trained": self.model is not None,
            "metrics": self.model_metrics,
            "parameters": ML_CONFIG,
            "feature_count": len(self.feature_names) if self.feature_names else 0,
            "features": list(self.feature_names or []),
            "feature_importances": self.feature_importances(),
            # False while recent sales records have a hole: products then use past sales
            "forecast_in_use": self.forecast_in_use(),
        }


