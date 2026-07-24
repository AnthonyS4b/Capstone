"""
Flask API Server for ML Recommendation Engine.

Provides REST endpoints for product recommendations, sales forecasting,
model training, and strategy recommendations. Also includes a direct-DB
fallback so the PHP front-end can display data even when the ML model
is not yet trained.
"""

import logging
import os
import sys
from datetime import datetime, timedelta
from typing import Dict, Any, List, Optional, Tuple

import numpy as np
from flask import Flask, request, jsonify
from flask_cors import CORS

# Handle imports for both module and script execution
try:
    from .data_service import DataProcessor, DatabaseConnector
    from .ml_config import API_CONFIG, CONFIDENCE_LEVELS, RISK_LEVELS, THRESHOLDS, STRATEGIES, RECOMMENDATION_POLICY
    from .recommendation_model import RecommendationModel
except ImportError:
    from data_service import DataProcessor, DatabaseConnector
    from ml_config import API_CONFIG, CONFIDENCE_LEVELS, RISK_LEVELS, THRESHOLDS, STRATEGIES, RECOMMENDATION_POLICY
    from recommendation_model import RecommendationModel

# ============================================================================
# INITIALIZATION
# ============================================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app)

# Sorting weights for hierarchical display
RISK_LEVEL_WEIGHTS = {
    "CRITICAL": 4,
    "WARNING": 3,
    "MONITOR": 2,
    "LOW": 1
}

# Global model instance (lazy-loaded on first request)
recommender: Optional[RecommendationModel] = None


# ============================================================================
# INITIALIZATION HOOKS
# ============================================================================


@app.before_request
def initialize_model() -> None:
    """Initialize model on first request (lazy load)."""
    global recommender
    if recommender is None:
        recommender = RecommendationModel()


# ============================================================================
# HEALTH CHECK ENDPOINT
# ============================================================================


@app.route("/health", methods=["GET"])
def health_check() -> Tuple[Any, int]:
    """API health check endpoint."""
    return (
        jsonify(
            {
                "status": "ok",
                "model_loaded": recommender.model is not None if recommender else False,
                "timestamp": datetime.now().isoformat(),
            }
        ),
        200,
    )


# ============================================================================
# RECOMMENDATION ENDPOINTS
# ============================================================================


@app.route("/api/recommendations", methods=["GET"])
def get_recommendations() -> Tuple[Any, int]:
    """
    Get product recommendations.

    Query Parameters:
        product_id (int, optional): Specific product ID
        limit (int, default=50): Maximum products to return
    """
    try:
        product_id = request.args.get("product_id", type=int)
        limit = request.args.get("limit", default=50, type=int)

        if product_id:
            recommendation = _get_single_recommendation(product_id)
            if recommendation:
                return jsonify({"recommendations": [recommendation]}), 200
            else:
                return jsonify({"error": "Product not found or no action needed"}), 404
        else:
            recommendations = _get_all_recommendations(limit)
            return jsonify({"recommendations": recommendations}), 200

    except Exception as e:
        logger.error(f"Error in get_recommendations: {e}")
        return jsonify({"error": str(e)}), 500


# ============================================================================
# DIRECT DB RECOMMENDATIONS (no ML model required)
# ============================================================================


@app.route("/api/recommendations/db", methods=["GET"])
def get_db_recommendations() -> Tuple[Any, int]:
    """
    Get recommendations purely from database rules — no ML model required.
    Triggers when:
      - Product is expiring within 14 days, OR
      - Product monthly sales velocity < slow_moving_threshold

    This ensures the front-end always has data even before the model is trained.
    """
    try:
        limit = request.args.get("limit", default=50, type=int)
        recommendations = _build_db_recommendations(limit)
        return jsonify({"recommendations": recommendations, "source": "db_rules"}), 200
    except Exception as e:
        logger.error(f"Error in get_db_recommendations: {e}")
        return jsonify({"error": str(e)}), 500


def _build_db_recommendations(limit: int) -> List[Dict[str, Any]]:
    """
    Build recommendations using pure SQL aggregation — no ML model dependency.
    """
    query = """
        SELECT
            p.id,
            p.name,
            p.price,
            p.cost_price,
            p.stock,
            p.unit,
            p.expiration_date,
            p.date_added,
            p.created_at AS product_created_at,
            c.name AS category_name,
            -- Robust subquery for 90-day total
            (SELECT COALESCE(SUM(ti2.quantity), 0) 
             FROM transaction_items ti2 
             JOIN sales s2 ON s2.transaction_id = ti2.transaction_id 
             WHERE ti2.product_id = p.id 
               AND s2.status = 'completed' 
               AND s2.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS total_sold_90d,
            -- Robust subquery for 30-day total (Monthly Sales)
            (SELECT COALESCE(SUM(ti3.quantity), 0) 
             FROM transaction_items ti3 
             JOIN sales s3 ON s3.transaction_id = ti3.transaction_id 
             WHERE ti3.product_id = p.id 
               AND s3.status = 'completed' 
               AND s3.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS monthly_sales,
            (SELECT COALESCE(SUM(ti4.quantity), 0) 
             FROM transaction_items ti4 
             JOIN sales s4 ON s4.transaction_id = ti4.transaction_id 
             WHERE ti4.product_id = p.id 
               AND s4.status = 'completed' 
               AND s4.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS total_sold_30d,
            -- Use the earliest batch expiration for accurate near-expiry detection (FEFO)
            DATEDIFF(
                COALESCE(
                    (SELECT MIN(pb.expiration_date) FROM product_batches pb 
                     WHERE pb.product_id = p.id AND pb.stock > 0 AND pb.status = 'active' 
                       AND pb.expiration_date IS NOT NULL),
                    p.expiration_date
                ),
                CURDATE()
            ) AS days_until_expiry,
            DATEDIFF(CURDATE(), COALESCE(p.date_added, p.created_at)) AS days_in_stock
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.deleted_at IS NULL
          AND p.archived_at IS NULL
          AND p.stock > 0
          AND NOT EXISTS (
              SELECT 1 FROM strategy_history 
              WHERE product_id = p.id 
                AND status = 'applied' 
                AND (ended_at IS NULL OR ended_at > NOW())
          )
        GROUP BY p.id
        HAVING
            (days_until_expiry IS NOT NULL AND days_until_expiry <= %s)
            OR monthly_sales < %s
            OR days_in_stock >= %s
        ORDER BY days_until_expiry ASC, monthly_sales ASC
        LIMIT %s
    """
    try:
        rows = DatabaseConnector.execute_query(
            query,
            (
                RECOMMENDATION_POLICY["expiry_within_days"],
                RECOMMENDATION_POLICY["monthly_sales_below"],
                RECOMMENDATION_POLICY["days_in_stock_at_least"],
                limit,
            ),
        )
    except Exception as e:
        logger.error(f"DB recommendations query failed: {e}")
        return []

    results = []
    for row in rows:
        price = float(row.get("price") or 0)
        cost = float(row.get("cost_price") or 0)
        # Fix: use price * 0.7 estimate when cost_price is 0, not 1
        if cost <= 0:
            cost = price * 0.7 if price > 0 else 1.0
        stock = int(row.get("stock") or 0)
        unit_str = str(row.get("unit") or "packaged").lower()
        is_packaged = not ("kilo" in unit_str or "gram" in unit_str)
        velocity = float(row.get("monthly_sales") or 0)
        days_expiry = row.get("days_until_expiry")
        days_expiry = int(days_expiry) if days_expiry is not None else 999
        days_in_stock = int(row.get("days_in_stock") or 0)

        is_critical = days_expiry <= THRESHOLDS["critical_stock_days"] and days_expiry >= 0
        is_slow = velocity < RECOMMENDATION_POLICY["monthly_sales_below"]

        # Compute simple risk score
        expiry_factor = max(0.0, min(1.0, 1 - (days_expiry / 30))) if days_expiry < 30 else 0.0
        velocity_factor = max(0.0, 1 - (velocity / 100)) if velocity < 100 else 0.0
        stock_factor = min(1.0, stock / 100) if stock > 0 else 1.0
        risk_score = expiry_factor * 0.4 + velocity_factor * 0.4 + stock_factor * 0.2

        risk_level = _determine_risk_level(risk_score, days_in_stock, days_expiry)
        risk_level_config = RISK_LEVELS.get(risk_level, RISK_LEVELS["LOW"])
        markup_pct = ((price - cost) / cost) * 100 if cost > 0 else 0

        # Fetch strategy IDs already tried within the no-repeat window
        recent_strategy_ids = _get_recent_strategy_ids(int(row["id"]))

        # Pick strategies from DB — filtered by risk_level, excluding recently-used ones
        strategies = _pick_strategies_from_db(
            is_critical, is_slow, stock > 20, markup_pct, risk_level, is_packaged,
            used_strategy_ids=recent_strategy_ids,
        )

        # Count historical strategies
        history_count = _get_strategy_history_count(int(row["id"]))

        # Better predicted_monthly_sales: when velocity is 0, estimate from stock age
        if velocity > 0:
            predicted_monthly_sales = round(velocity * 1.15, 2)
        elif stock > 0 and days_in_stock > 0:
            # Estimate: if no sales in 90 days, predict very low future sales
            predicted_monthly_sales = round(max(0.5, stock / max(days_in_stock / 30, 1) * 0.3), 2)
        else:
            predicted_monthly_sales = 0

        # Extract date_added for ML tracking (prefer date_added column)
        date_added = str(row.get("date_added") or row.get("product_created_at") or "") if (row.get("date_added") or row.get("product_created_at")) else None

        rec = {
            "product_id": int(row["id"]),
            "product_name": row["name"],
            "current_price": price,
            "cost_price": cost,
            "current_stock": stock,
            "category": row.get("category_name") or "General",
            "days_until_expiry": days_expiry,
            "expiration_date": str(row["expiration_date"]) if row["expiration_date"] else None,
            "days_in_stock": days_in_stock,
            "monthly_sales": round(velocity, 2),
            "total_sold_last_30d": int(row.get("total_sold_30d") or 0),
            "total_sold_90d": int(row.get("total_sold_90d") or 0),
            "risk_score": round(risk_score, 3),
            "risk_level": risk_level,
            "risk_color": RISK_LEVELS[risk_level]["color"],
            "predicted_monthly_sales": predicted_monthly_sales,
            "confidence": 0.65,
            "confidence_label": "Medium Confidence",
            "potential_revenue": round(max(velocity, predicted_monthly_sales) * price, 2),
            "strategies": strategies,
            "is_critical_expiry": is_critical,
            "is_slow_moving": is_slow,
            "history_count": history_count,
            "date_added": date_added,
            "source": "db_rules",
        }

        # Add monitor message for MONITOR-level products
        if risk_level == "MONITOR":
            rec["monitor_message"] = (
                "This item is selling normally. Monitor it weekly and "
                "revisit if sales decline for 2+ consecutive weeks."
            )

        # 14-Day Minimum Rule: Skip if new, unless it is expiring
        if days_in_stock < RECOMMENDATION_POLICY["minimum_days_in_stock"] and not is_critical:
            continue

        results.append(rec)

    # Hierarchical Sort: 
    # 1. Nearly Expiry (Weight 5) 
    # 2. Risk Level (4, 3, 2, 1) 
    # 3. Days in Stock (Tie-breaker)
    results.sort(
        key=lambda x: (
            5 if x.get("is_critical_expiry") else RISK_LEVEL_WEIGHTS.get(x["risk_level"], 1),
            x["days_in_stock"],
            x["risk_score"]
        ),
        reverse=True
    )
    return results


def _determine_risk_level(risk_score: float, days_in_stock: int = 0, days_until_expiry: int = 999) -> str:
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
    
    # 2. WARNING: 50-89 days
    if days_in_stock >= THRESHOLDS["days_in_stock_warning"]:
        return "WARNING"
        
    # 3. MONITOR: 1-49 days
    return "MONITOR" if days_in_stock > 0 else "LOW"


def _get_strategy_history_count(product_id: int) -> int:
    """Get count of historical strategies applied to a product."""
    try:
        row = DatabaseConnector.execute_query(
            "SELECT COUNT(*) AS cnt FROM strategy_history WHERE product_id = %s",
            (product_id,),
            fetch_one=True,
        )
        return int(row["cnt"]) if row else 0
    except Exception:
        return 0


NO_REPEAT_LOOKBACK_DAYS = RECOMMENDATION_POLICY["strategy_cooldown_days"]


def _get_recent_strategy_ids(product_id: int, lookback_days: int = NO_REPEAT_LOOKBACK_DAYS) -> List[str]:
    """
    Return the list of strategy IDs that were already applied to this product
    within the last `lookback_days` days.  These will be excluded from new
    recommendations so the engine never cycles the same advice.
    """
    try:
        rows = DatabaseConnector.execute_query(
            """SELECT DISTINCT strategy_id
               FROM strategy_history
               WHERE product_id = %s
                 AND started_at >= DATE_SUB(NOW(), INTERVAL %s DAY)""",
            (product_id, lookback_days),
        )
        return [str(r["strategy_id"]) for r in rows] if rows else []
    except Exception as e:
        logger.warning(f"Could not fetch recent strategy IDs for product {product_id}: {e}")
        return []


def _load_strategies_from_db() -> List[Dict[str, Any]]:
    """Load strategy templates from the database."""
    try:
        rows = DatabaseConnector.execute_query(
            "SELECT * FROM strategy_templates WHERE is_active = 1 ORDER BY priority ASC"
        )
        if not rows:
            return []

        strategies = []
        for row in rows:
            conditions = row.get("conditions") or "[]"
            steps = row.get("implementation_steps") or "[]"
            risk_levels_raw = row.get("risk_levels") or "[]"

            # Parse JSON fields
            import json
            if isinstance(conditions, str):
                conditions = json.loads(conditions)
            if isinstance(steps, str):
                steps = json.loads(steps)
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
                "description": row.get("description") or "",
            })
        return strategies
    except Exception as e:
        logger.warning(f"Could not load strategies from DB, using fallback: {e}")
        return []


# Escalation advisory returned when all applicable strategies have been tried recently
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


def _pick_strategies_from_db(
    is_critical: bool,
    is_slow: bool,
    is_high_stock: bool,
    markup_pct: float,
    risk_level: str = "WARNING",
    is_packaged: bool = True,
    used_strategy_ids: Optional[List[str]] = None,
) -> List[Dict[str, Any]]:
    """
    Pick best strategies from DB templates based on product risk level and conditions.

    `used_strategy_ids`: strategy IDs already applied within the no-repeat window.
    Strategies in this list are excluded so the engine never cycles the same advice.
    When all applicable strategies are exhausted the escalation advisory is returned.
    """
    if used_strategy_ids is None:
        used_strategy_ids = []

    db_strategies = _load_strategies_from_db()

    # Fallback to hardcoded if DB is empty
    if not db_strategies:
        db_strategies = STRATEGIES
        # Convert old format
        for s in db_strategies:
            if "implementation_steps" not in s:
                s["implementation_steps"] = []
            if "why_it_works" not in s:
                s["why_it_works"] = ""

    # ── Rule 1: Filter by risk level ──────────────────────────────────────────
    filtered_strategies = []
    for strategy in db_strategies:
        strategy_risk_levels = strategy.get("risk_levels", [])
        if strategy_risk_levels:
            if risk_level in strategy_risk_levels:
                filtered_strategies.append(strategy)
        else:
            filtered_strategies.append(strategy)

    # If no strategies match the risk level, fall back to all
    if not filtered_strategies:
        filtered_strategies = db_strategies

    # ── Rule 2: Exclude recently-tried strategies (no-repeat window) ──────────
    used_set = set(str(sid) for sid in used_strategy_ids)
    fresh_strategies = [s for s in filtered_strategies if str(s["id"]) not in used_set]

    # If every risk-appropriate strategy has been tried recently, note that for
    # the escalation fallback but still score from the fresh pool.
    all_exhausted = len(fresh_strategies) == 0
    strategies_to_score = fresh_strategies if not all_exhausted else filtered_strategies

    is_moderate_stock = not is_high_stock  # simplified
    recommended = []
    for strategy in strategies_to_score:
        fit_score = 0.0
        conditions = strategy.get("conditions", [])
        if "critical_expiry" in conditions and is_critical:
            fit_score += 0.5
        if "slow_moving" in conditions and is_slow:
            fit_score += 0.5
        if "high_stock" in conditions and is_high_stock:
            fit_score += 0.3
        if "moderate_stock" in conditions and is_moderate_stock:
            fit_score += 0.2
        if "complementary_products" in conditions:
            fit_score += 0.1
        if "low_priority" in conditions and risk_level == "MONITOR":
            fit_score += 0.4
        if "seasonal" in conditions:
            fit_score += 0.1
        if "packaged" in conditions:
            if not is_packaged:
                fit_score -= 1.0  # Disqualify: BOGO doesn't work for loose/bulk items

        if fit_score > 0:
            d_range = strategy.get("discount_range", (0, 10))
            d_min, d_max = d_range if isinstance(d_range, (list, tuple)) else (0, 10)
            disc = min(d_max, max(d_min, markup_pct * 0.2))

            exp_impact = strategy.get("expected_impact", (0, 0))
            if isinstance(exp_impact, (list, tuple)):
                impact_min, impact_max = exp_impact
            else:
                impact_min = strategy.get("expected_impact_min", 0)
                impact_max = strategy.get("expected_impact_max", 0)

            entry = {
                "strategy_id": str(strategy["id"]),
                "strategy_name": strategy["name"],
                "priority": strategy["priority"],
                "fit_score": fit_score,
                "recommended_discount": round(disc, 1),
                "duration_days": strategy["duration_days"],
                "expected_impact_min": int(impact_min),
                "expected_impact_max": int(impact_max),
                "implementation_steps": strategy.get("implementation_steps", []),
                "why_it_works": strategy.get("why_it_works", ""),
                "risk_level_target": risk_level,
                # Tag previously-used strategies so the UI can warn the user
                "previously_used": str(strategy["id"]) in used_set,
            }

            # Add monitor message for MONITOR strategies
            if risk_level == "MONITOR":
                entry["monitor_message"] = (
                    "This item is selling normally. Monitor it weekly and "
                    "revisit if sales decline for 2+ consecutive weeks."
                )

            recommended.append(entry)

    # Deterministic ordering prevents equal candidates from swapping places on
    # refresh.  Template priority is the stable tie-breaker displayed in the UI.
    recommended.sort(key=lambda x: (-x["fit_score"], x["priority"], x["strategy_id"]))

    # Limit to 2 strategies
    result = recommended[:2]

    # ── Rule 3: Escalation when all fresh strategies are exhausted ────────────
    if all_exhausted:
        logger.info(
            f"All strategies already tried recently (used={used_strategy_ids}). "
            "Returning escalation advisory."
        )
        return [_ESCALATION_ADVISORY.copy()]

    # For MONITOR with no matching strategies, provide a default advisory
    if risk_level == "MONITOR" and not result:
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
            "why_it_works": "Regular monitoring catches early signs of decline before they become critical.",
            "risk_level_target": "MONITOR",
            "monitor_message": (
                "This item is selling normally. Monitor it weekly and "
                "revisit if sales decline for 2+ consecutive weeks."
            ),
        }]

    return result


# ============================================================================
# FORECASTING ENDPOINTS
# ============================================================================


@app.route("/api/forecast", methods=["POST"])
def get_forecast() -> Tuple[Any, int]:
    """Get sales forecast for a product with strategy-based adjustment."""
    try:
        data = request.json
        product_id = data.get("product_id")
        days_ahead = data.get("days_ahead", 30)

        if not product_id:
            return jsonify({"error": "product_id required"}), 400

        product = _get_product_data(product_id)
        if not product:
            return jsonify({"error": "Product not found"}), 404

        features = DataProcessor.extract_features(product_id, product)
        if not features:
            return jsonify({"error": "Could not extract features"}), 400

        # Build feature vector dynamically matching the trained input structure
        X = np.array(
            [
                [features[k] for k in features.keys() if k not in ("product_id", "date_added")]
            ]
        )

        # Use ML model if available, else use stats-based estimate
        if recommender and recommender.model is not None:
            base_predicted_sales = recommender.predict_sales(X)[0]
        else:
            base_predicted_sales = float(features["monthly_sales_velocity"])

        # Strategy uplift
        risk_score = recommender.calculate_risk_score(product_id, product, features) if recommender else 0.5
        strategies = recommender.recommend_strategy(product_id, product, features, risk_score) if recommender else []

        uplift_multiplier = 1.0
        applied_strategy = None
        if strategies:
            strategy = strategies[0]
            avg_impact = (strategy["expected_impact_min"] + strategy["expected_impact_max"]) / 2.0
            uplift_multiplier = 1.0 + (avg_impact / 100.0)
            applied_strategy = strategy["strategy_name"]

        final_predicted_sales = base_predicted_sales * uplift_multiplier
        daily_forecast = _generate_daily_forecast(final_predicted_sales, days_ahead)

        confidence = (
            recommender.get_prediction_confidence(
                product_id, base_predicted_sales, features["monthly_sales_velocity"]
            )
            if recommender
            else 0.65
        )

        return (
            jsonify(
                {
                    "product_id": product_id,
                    "product_name": product["name"],
                    "forecast_period": f"{days_ahead} days",
                    "base_prediction": round(float(base_predicted_sales), 2),
                    "applied_strategy": applied_strategy,
                    "uplift_applied": round((uplift_multiplier - 1) * 100, 1),
                    "predicted_total": int(round(final_predicted_sales)),
                    "predicted_daily_avg": round(final_predicted_sales / days_ahead, 2),
                    "daily_breakdown": daily_forecast,
                    "confidence": confidence,
                    "generated_at": datetime.now().isoformat(),
                }
            ),
            200,
        )

    except Exception as e:
        logger.error(f"Error in get_forecast: {e}")
        return jsonify({"error": str(e)}), 500


# ============================================================================
# MODEL TRAINING ENDPOINTS
# ============================================================================


@app.route("/api/train-model", methods=["POST"])
def train_model() -> Tuple[Any, int]:
    """Retrain the ML model with latest data."""
    try:
        logger.info("Starting model retraining...")
        success = recommender.train_model()

        if success:
            return (
                jsonify(
                    {
                        "status": "success",
                        "message": "Model trained successfully",
                        "model_info": recommender.get_model_info(),
                    }
                ),
                200,
            )
        else:
            return (
                jsonify(
                    {
                        "status": "error",
                        "message": "Model training failed — insufficient data. Import the SQL setup file first.",
                    }
                ),
                400,
            )

    except Exception as e:
        logger.error(f"Error in train_model: {e}")
        return jsonify({"error": str(e)}), 500


# ============================================================================
# MODEL INFO ENDPOINTS
# ============================================================================


@app.route("/api/model-info", methods=["GET"])
def model_info() -> Tuple[Any, int]:
    """Get information about current ML model."""
    try:
        info = recommender.get_model_info()
        return jsonify(info), 200
    except Exception as e:
        logger.error(f"Error in model_info: {e}")
        return jsonify({"error": str(e)}), 500


# ============================================================================
# LOGGING ENDPOINTS
# ============================================================================


@app.route("/api/save-recommendation", methods=["POST"])
def save_recommendation() -> Tuple[Any, int]:
    """Apply a strategy: update product price, log to strategy_history and ml_recommendation_logs."""
    try:
        data = request.json
        product_id = data.get("product_id")
        strategy_id = data.get("strategy_id")
        discount = float(data.get("discount_percentage", 0))
        notes = data.get("notes", "")

        if not product_id or not strategy_id:
            return jsonify({"error": "product_id and strategy_id required"}), 400

        # 1. Get current product price
        product = DatabaseConnector.execute_query(
            "SELECT id, price, name FROM products WHERE id = %s AND deleted_at IS NULL",
            (product_id,), fetch_one=True
        )
        if not product:
            return jsonify({"error": "Product not found"}), 404

        original_price = float(product["price"])
        discounted_price = round(original_price * (1 - discount / 100), 2)

        # 2. Get strategy template details for duration
        strategy_tmpl = DatabaseConnector.execute_query(
            "SELECT duration_days FROM strategy_templates WHERE id = %s",
            (strategy_id,), fetch_one=True
        )
        duration_days = int(strategy_tmpl["duration_days"]) if strategy_tmpl else 7

        # 3. Insert into strategy_history
        DatabaseConnector.execute_insert_update(
            """INSERT INTO strategy_history
               (product_id, strategy_id, discount_applied, original_price,
                discounted_price, status, started_at, ended_at, outcome_notes, created_by)
               VALUES (%s, %s, %s, %s, %s, 'applied', NOW(),
                       DATE_ADD(NOW(), INTERVAL %s DAY), %s, %s)""",
            (product_id, strategy_id, discount, original_price,
             discounted_price, duration_days, notes, data.get("user_id"))
        )

        # 4. Update product selling price to discounted price
        if discount > 0:
            DatabaseConnector.execute_insert_update(
                "UPDATE products SET price = %s, updated_at = NOW() WHERE id = %s",
                (discounted_price, product_id)
            )

        # 5. Log to ml_recommendation_logs
        DatabaseConnector.execute_insert_update(
            """INSERT INTO ml_recommendation_logs
               (product_id, strategy_id, discount_percentage, status, notes, created_at)
               VALUES (%s, %s, %s, 'applied', %s, NOW())""",
            (product_id, strategy_id, discount, notes)
        )

        logger.info(f"Strategy '{strategy_id}' applied to product #{product_id}: "
                     f"₱{original_price} → ₱{discounted_price} ({discount}% off, {duration_days} days)")

        return jsonify({
            "status": "saved",
            "message": f"Strategy applied! Price updated from ₱{original_price:.2f} to ₱{discounted_price:.2f}",
            "original_price": original_price,
            "discounted_price": discounted_price,
            "duration_days": duration_days
        }), 201

    except Exception as e:
        logger.error(f"Error in save_recommendation: {e}")
        return jsonify({"error": str(e)}), 500


@app.route("/api/active-strategies", methods=["GET"])
def get_active_strategies() -> Tuple[Any, int]:
    """Get all active marketing strategies."""
    try:
        query = """
            SELECT sh.id, sh.product_id, p.name as product_name, sh.strategy_id, st.name as strategy_name,
                   sh.discount_applied, sh.original_price, sh.discounted_price, 
                   sh.started_at, sh.ended_at
            FROM strategy_history sh
            JOIN products p ON sh.product_id = p.id
            LEFT JOIN strategy_templates st ON sh.strategy_id = st.id
            WHERE sh.status = 'applied'
               AND (sh.ended_at IS NULL OR sh.ended_at > NOW())
            ORDER BY sh.started_at DESC
        """
        strategies = DatabaseConnector.execute_query(query)
        
        # Format dates for JSON
        for s in strategies:
            if s.get("started_at"): s["started_at"] = str(s["started_at"])
            if s.get("ended_at"): s["ended_at"] = str(s["ended_at"])
            
        return jsonify({
            "success": True,
            "strategies": list(strategies)
        }), 200

    except Exception as e:
        logger.error(f"Error fetching active strategies: {e}")
        return jsonify({"success": False, "error": str(e)}), 500


@app.route("/api/cancel-strategy", methods=["POST"])
def cancel_strategy() -> Tuple[Any, int]:
    """Cancel an active strategy and revert the product price."""
    try:
        data = request.json
        history_id = data.get("id")

        if not history_id:
            return jsonify({"success": False, "error": "History ID required"}), 400

        # Get the strategy from history first
        strategy = DatabaseConnector.execute_query(
            "SELECT product_id, original_price FROM strategy_history WHERE id = %s AND status = 'applied'",
            (history_id,), fetch_one=True
        )
        
        if not strategy:
            return jsonify({"success": False, "error": "Active strategy not found"}), 404
            
        product_id = strategy["product_id"]
        original_price = strategy["original_price"]

        # Cancel the strategy record
        DatabaseConnector.execute_insert_update(
            "UPDATE strategy_history SET status = 'cancelled', ended_at = NOW() WHERE id = %s",
            (history_id,)
        )

        # Revert the product price
        DatabaseConnector.execute_insert_update(
            "UPDATE products SET price = %s, updated_at = NOW() WHERE id = %s",
            (original_price, product_id)
        )
        
        # Log it implicitly via strategy_history update, no ml logs needed for stop action
        logger.info(f"Strategy ID {history_id} cancelled. Reverted product #{product_id} to ₱{original_price}")

        return jsonify({
            "success": True,
            "message": f"Strategy cancelled successfully. Price reverted to ₱{original_price:.2f}"
        }), 200

    except Exception as e:
        logger.error(f"Error cancelling strategy: {e}")
        return jsonify({"success": False, "error": str(e)}), 500


# ============================================================================
# HELPER FUNCTIONS
# ============================================================================


def _get_product_data(product_id: int) -> Optional[Dict[str, Any]]:
    query = """
        SELECT p.id, p.name, p.price, p.cost_price, p.stock, p.unit, p.expiration_date,
               p.date_added, p.created_at, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id = %s AND p.deleted_at IS NULL
    """
    return DatabaseConnector.execute_query(query, (product_id,), fetch_one=True)


def _get_single_recommendation(product_id: int) -> Optional[Dict[str, Any]]:
    try:
        product = _get_product_data(product_id)
        if not product:
            return None

        features = DataProcessor.extract_features(product_id, product)
        if not features:
            return None

        is_critical = features["is_critical_expiry"]
        is_slow = features["monthly_sales_velocity"] < RECOMMENDATION_POLICY["monthly_sales_below"]

        is_aging = features.get("days_in_stock", 0) >= RECOMMENDATION_POLICY["days_in_stock_at_least"]
        if not (is_critical or is_slow or is_aging):
            return None

        risk_score = recommender.calculate_risk_score(product_id, product, features)
        risk_level = recommender.determine_risk_level(
            risk_score, 
            features.get("days_in_stock", 0), 
            features.get("days_until_expiry", 999)
        )

        # Build feature vector dynamically matching the trained input structure
        X = np.array(
            [
                [features[k] for k in features.keys() if k not in ("product_id", "date_added")]
            ]
        )

        if recommender.model is not None:
            predicted_sales = recommender.predict_sales(X)
            pred_val = float(predicted_sales[0])
        else:
            pred_val = float(features["monthly_sales_velocity"])

        confidence = recommender.get_prediction_confidence(
            product_id, pred_val, features["monthly_sales_velocity"]
        )
        # Fetch strategy IDs already tried within the no-repeat window
        recent_strategy_ids = _get_recent_strategy_ids(product_id)
        strategies = recommender.recommend_strategy(
            product_id, product, features, risk_score, risk_level,
            used_strategy_ids=recent_strategy_ids,
        )

        # Calculate days in stock from features (already computed correctly)
        days_in_stock = features.get("days_in_stock", 0)

        # Fix cost_price: use price * 0.7 when cost_price is 0
        cost_price = float(product.get("cost_price") or 0)
        if cost_price <= 0:
            cost_price = float(product["price"]) * 0.7

        # Better predicted sales for zero-velocity products
        velocity = float(features["monthly_sales_velocity"])
        if pred_val <= 0 and velocity <= 0:
            stock = int(product["stock"])
            if stock > 0 and days_in_stock > 0:
                pred_val = round(max(0.5, stock / max(days_in_stock / 30, 1) * 0.3), 2)

        history_count = _get_strategy_history_count(product_id)

        # Extract date_added
        date_added = str(product.get("date_added") or product.get("created_at") or "") if (product.get("date_added") or product.get("created_at")) else None

        rec = {
            "product_id": product_id,
            "product_name": product["name"],
            "current_price": float(product["price"]),
            "cost_price": cost_price,
            "current_stock": int(product["stock"]),
            "category": product.get("category_name") or "General",
            "days_until_expiry": features["days_until_expiry"],
            "expiration_date": str(product["expiration_date"]) if product["expiration_date"] else None,
            "days_in_stock": days_in_stock,
            "monthly_sales": velocity,
            "total_sold_last_30d": features.get("total_sold_last_30d", 0),
            "total_sold_90d": features.get("total_sold_90d", 0),
            "risk_score": float(risk_score),
            "risk_level": risk_level,
            "risk_color": RISK_LEVELS[risk_level]["color"],
            "predicted_monthly_sales": pred_val,
            "confidence": float(confidence),
            "confidence_label": _get_confidence_label(confidence),
            "potential_revenue": round(max(pred_val, velocity) * float(product["price"]), 2),
            "strategies": strategies,
            "is_critical_expiry": bool(is_critical),
            "is_slow_moving": bool(is_slow),
            "history_count": history_count,
            "date_added": date_added,
        }

        # Add monitor message for MONITOR-level products
        if risk_level == "MONITOR":
            rec["monitor_message"] = (
                "This item is selling normally. Monitor it weekly and "
                "revisit if sales decline for 2+ consecutive weeks."
            )

        return rec

    except Exception as e:
        logger.error(f"Error getting single recommendation: {e}")
        return None


def _get_all_recommendations(limit: int) -> List[Dict[str, Any]]:
    try:
        query = """
            SELECT id, name, price, cost_price, stock, expiration_date
            FROM products
            WHERE deleted_at IS NULL 
              AND archived_at IS NULL 
              AND stock > 0
              AND (expiration_date IS NULL OR expiration_date >= CURDATE())
              AND NOT EXISTS (
                  SELECT 1 FROM strategy_history 
                  WHERE product_id = products.id 
                    AND status = 'applied' 
                    AND (ended_at IS NULL OR ended_at > NOW())
              )
            LIMIT %s
        """
        products = DatabaseConnector.execute_query(query, (limit,))
        recommendations = []
        for product in products:
            rec = _get_single_recommendation(product["id"])
            if rec:
                # 14-Day Minimum Rule: Skip if new, unless it is expiring
                if (rec.get("days_in_stock", 0) < RECOMMENDATION_POLICY["minimum_days_in_stock"]
                        and not rec.get("is_critical_expiry")):
                    continue
                recommendations.append(rec)

        # Hierarchical Sort: 
        # 1. Nearly Expiry (Weight 5) 
        # 2. Risk Level (4, 3, 2, 1) 
        # 3. Days in Stock (Tie-breaker)
        # Hierarchical Sort
        RISK_LEVEL_WEIGHTS = {
            "CRITICAL": 4,
            "WARNING": 3,
            "MONITOR": 2,
            "LOW": 1
        }
        recommendations.sort(
            key=lambda x: (
                5 if x.get("is_critical_expiry") else RISK_LEVEL_WEIGHTS.get(x["risk_level"], 1),
                x["days_in_stock"],
                x["risk_score"]
            ),
            reverse=True
        )
        return recommendations

    except Exception as e:
        logger.error(f"Error getting all recommendations: {e}")
        return []


def _generate_daily_forecast(total_sales: float, days: int = 30) -> List[Dict[str, Any]]:
    """Generate deterministic daily breakdown with weekly seasonality."""
    daily_avg = total_sales / days
    weight_map = {1: 0.8, 2: 0.8, 3: 0.9, 4: 1.0, 5: 1.3, 6: 1.5, 7: 1.4}
    current_date = datetime.now()
    forecast = []
    for day_offset in range(1, days + 1):
        target_date = current_date + timedelta(days=day_offset)
        weight = weight_map.get(target_date.isoweekday(), 1.0)
        forecast.append(
            {
                "day": day_offset,
                "date": target_date.strftime("%Y-%m-%d"),
                "predicted_units": int(round(daily_avg * weight)),
            }
        )
    return forecast


def _get_confidence_label(confidence: float) -> str:
    for level, config in CONFIDENCE_LEVELS.items():
        if confidence >= config["min"]:
            return config["label"]
    return "Low Confidence"


# ============================================================================
# SERVER MAIN & STARTUP
# ============================================================================


def main() -> None:
    """Main entry point for ML server startup."""
    print("\n" + "=" * 50)
    print("ML Recommendation API Server")
    print("=" * 50)

    if sys.version_info < (3, 8):
        print("ERROR: Python 3.8+ required")
        sys.exit(1)

    print(f"[OK] Python {sys.version.split()[0]}")
    print(f"[OK] Starting on http://127.0.0.1:{API_CONFIG['port']}")
    print("Press Ctrl+C to stop\n")

    try:
        app.run(
            host=API_CONFIG["host"],
            port=API_CONFIG["port"],
            debug=API_CONFIG["debug"],
            threaded=API_CONFIG["threaded"],
        )
    except KeyboardInterrupt:
        print("\nServer stopped.")
        sys.exit(0)
    except Exception as e:
        print(f"\nERROR: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
