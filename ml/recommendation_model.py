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
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler

try:
    from .data_service import DataProcessor, DatabaseConnector
    from .ml_config import (
        CONFIDENCE_LEVELS,
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
        ML_CONFIG,
        MODEL_PATHS,
        RISK_LEVELS,
        RECOMMENDATION_POLICY,
        STRATEGIES,
        THRESHOLDS,
    )

logger = logging.getLogger(__name__)

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
        self.load_model()

    # ========================================================================
    # MODEL LOADING AND TRAINING
    # ========================================================================

    def load_model(self) -> None:
        """
        Load pre-trained model and scaler from disk.

        Falls back to training new model if saved files not found.
        """
        try:
            if (
                os.path.exists(MODEL_PATHS["model"])
                and os.path.exists(MODEL_PATHS["scaler"])
            ):
                with open(MODEL_PATHS["scaler"], "rb") as f:
                    self.scaler = pickle.load(f)
                with open(MODEL_PATHS["model"], "rb") as f:
                    self.model = pickle.load(f)
                logger.info("Model loaded successfully")
            else:
                logger.warning("No pre-trained model found. Training new model.")
                self.train_model()
        except Exception as e:
            logger.error(f"Error loading model: {e}")
            self.train_model()

    def train_model(self) -> bool:
        """
        Train Random Forest model on all product sales data.

        Returns:
            bool: True if training successful, False otherwise
        """
        try:
            logger.info("Starting model training...")

            # Prepare training data
            products = DataProcessor.get_all_active_products()
            if not products:
                logger.error("No products available for training")
                return False

            X_list = []
            y_list = []
            feature_names = None

            # Extract features and target (next 30-day sales prediction)
            for product_id, product_data in products:
                try:
                    features = DataProcessor.extract_features(product_id, product_data)
                    if features and features["monthly_sales_velocity"] is not None:
                        if feature_names is None:
                            feature_names = [
                                k for k in features.keys() if k not in ("product_id", "date_added")
                            ]

                        X_list.append(
                            [features[key] for key in feature_names]
                        )
                        y_list.append(features["monthly_sales_velocity"])
                except Exception as e:
                    logger.warning(f"Error processing product {product_id}: {e}")
                    continue

            if len(X_list) < THRESHOLDS["min_training_records"]:
                logger.warning(f"Insufficient training data: {len(X_list)} records")
                return False

            X = np.array(X_list)
            y = np.array(y_list)

            # Handle NaN and inf values
            mask = np.isfinite(X).all(axis=1) & np.isfinite(y)
            X = X[mask]
            y = y[mask]

            if len(X) < THRESHOLDS["min_training_records"]:
                logger.warning("Insufficient valid training data after cleaning")
                return False

            # Scale features
            self.scaler = StandardScaler()
            X_scaled = self.scaler.fit_transform(X)

            # Split data (80-20)
            X_train, X_test, y_train, y_test = train_test_split(
                X_scaled, y, test_size=0.2, random_state=42
            )

            # Train Random Forest
            rf_params = {k: v for k, v in ML_CONFIG.items() if k != "model_type"}
            self.model = RandomForestRegressor(**rf_params)
            self.model.fit(X_train, y_train)

            # Evaluate model
            y_pred = self.model.predict(X_test)
            mse = mean_squared_error(y_test, y_pred)
            rmse = np.sqrt(mse)
            mae = mean_absolute_error(y_test, y_pred)
            r2 = r2_score(y_test, y_pred)

            self.model_metrics = {
                "mse": float(mse),
                "rmse": float(rmse),
                "mae": float(mae),
                "r2_score": float(r2),
                "training_samples": len(X_train),
                "test_samples": len(X_test),
                "training_date": datetime.now().isoformat(),
                "feature_count": X.shape[1],
            }

            # Store feature names
            self.feature_names = feature_names

            # Save model
            os.makedirs(MODEL_PATHS["models_dir"], exist_ok=True)
            with open(MODEL_PATHS["scaler"], "wb") as f:
                pickle.dump(self.scaler, f)
            with open(MODEL_PATHS["model"], "wb") as f:
                pickle.dump(self.model, f)

            logger.info(
                f"Model trained successfully. R² = {r2:.4f}, RMSE = {rmse:.4f}"
            )
            self._save_metrics()
            return True

        except Exception as e:
            logger.error(f"Error during model training: {e}")
            return False

    # ========================================================================
    # PREDICTION METHODS
    # ========================================================================

    def predict_sales(self, X: np.ndarray) -> Optional[np.ndarray]:
        """
        Predict next 30-day sales velocity.

        Args:
            X: Feature matrix (can be scaled or unscaled)

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

    def get_prediction_confidence(
        self, product_id: int, predicted_sales: float, historical_sales: float
    ) -> float:
        """
        Calculate confidence score for prediction.

        Based on data consistency and model certainty (0-1 scale).

        Args:
            product_id: Product ID
            predicted_sales: Model's predicted sales
            historical_sales: Historical average sales

        Returns:
            float: Confidence score between 0 and 1
        """
        try:
            if historical_sales == 0:
                return 0.5  # Low confidence for new products

            # Calculate variance in historical data
            sales_df = DataProcessor.get_product_sales_data(product_id, days_back=30)
            if sales_df.empty:
                return 0.5

            daily_sales = sales_df.groupby(
                sales_df["sale_date"].dt.date
            )["quantity"].sum()
            if len(daily_sales) < 3:
                return 0.6

            # Calculate coefficient of variation
            cv = daily_sales.std() / (daily_sales.mean() + 0.1)

            # Confidence inversely proportional to variance
            confidence = min(0.95, max(0.5, 1 - (cv / 3)))
            return float(confidence)
        except Exception:
            return 0.65

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
                if str(strategy.get("id")) == "buy_one_take_one" and not bogo_margin_safe:
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
            ]
            DatabaseConnector.execute_batch_insert(query, metrics_data)
        except Exception as e:
            logger.warning(f"Could not save metrics to DB: {e}")

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
        }


