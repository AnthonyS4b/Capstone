# ml/ml_config.py
"""
Machine Learning Configuration and Constants.

Central configuration module for ML models, database connections,
thresholds, and API settings.
"""

import os
from datetime import timedelta
from typing import Dict, Any

# ============================================================================
# DATABASE CONFIGURATION
# ============================================================================
DB_CONFIG: Dict[str, Any] = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "espenida_pos",
    "port": 3306,
}


# ============================================================================
# ML MODEL CONFIGURATION
# ============================================================================
ML_CONFIG: Dict[str, Any] = {
    "model_type": "RandomForestRegressor",
    "n_estimators": 100,
    "max_depth": 15,
    "min_samples_split": 5,
    "min_samples_leaf": 2,
    "random_state": 42,
    "n_jobs": -1,
    "verbose": 0,
}


# ============================================================================
# RECOMMENDATION THRESHOLDS
# ============================================================================
THRESHOLDS: Dict[str, Any] = {
    "critical_stock_days": 14,   # Items expiring within 14 days are critical
    "slow_moving_threshold": 50, # Monthly sales < 50 units = slow-moving (adjusted for mock data density)
    "low_confidence_threshold": 0.50,  # Minimum ML confidence
    "min_training_records": 3,   # Minimum product records needed to train
    "days_in_stock_critical": 90, # 90+ days = CRITICAL
    "days_in_stock_warning": 50,  # 50+ days = WARNING
}

# Recommendation delivery policy.  Keep these values together so the rules
# used to decide *whether* to show a recommendation are explicit and easy to
# tune without changing ranking or presentation code.
RECOMMENDATION_POLICY: Dict[str, Any] = {
    # A non-expiring product needs this much shelf time before it can alert.
    "minimum_days_in_stock": 14,
    # Any one of these conditions makes an eligible product recommendable.
    "monthly_sales_below": 50,       # fewer than 50 units in the last 30 days
    "days_in_stock_at_least": 50,    # aging inventory, regardless of sales
    "expiry_within_days": 14,        # imminent expiry always takes precedence
    # Same product + strategy is a repeat during this window.  Other eligible
    # strategies may still be offered, so a worsening risk tier can escalate.
    "strategy_cooldown_days": 30,
}


# ============================================================================
# DISCOUNT STRATEGY MATRIX (Priority-Based)
# ============================================================================
STRATEGIES: list = [
    # ── CRITICAL-level strategies (emergency / urgent liquidation) ──
    {
        "id": "emergency_clearance",
        "name": "Emergency Clearance Flash Sale",
        "priority": 1,
        "discount_range": (25, 40),
        "duration_days": 3,
        "conditions": ["critical_expiry", "slow_moving"],
        "expected_impact": (70, 105),
        "risk_levels": ["CRITICAL"],
        "why_it_works": "A time-limited deep discount creates urgency and recovers cash flow quickly. Items sitting too long lose value through spoilage risk and storage cost.",
        "implementation_steps": [
            "Set price at the recommended discounted amount",
            "Run promotion for 3 days only to create urgency",
            "Place item prominently at checkout or near entrance display",
            "Print a SALE signage with the original crossed-out price",
            "Announce to walk-in customers verbally at point of sale",
        ],
    },
    {
        "id": "deep_discount_liquidation",
        "name": "Deep Discount Liquidation",
        "priority": 2,
        "discount_range": (30, 50),
        "duration_days": 5,
        "conditions": ["critical_expiry", "high_stock"],
        "expected_impact": (80, 120),
        "risk_levels": ["CRITICAL"],
        "why_it_works": "When stock levels are high and risk is critical, aggressive pricing is the fastest path to recovering capital before total loss.",
        "implementation_steps": [
            "Apply the maximum safe discount to clear stock rapidly",
            "Create a dedicated clearance section in-store",
            "Offer bulk purchase incentives (e.g. extra 5% off for 3+ units)",
            "Alert loyal customers via text or social media about the deal",
            "Track daily sell-through rate to adjust pricing if needed",
        ],
    },
    {
        "id": "buy_one_take_one",
        "name": "Buy 1 Take 1 Blowout",
        "priority": 3,
        "discount_range": (40, 50),
        "duration_days": 3,
        "conditions": ["packaged", "critical_expiry", "slow_moving"],
        "expected_impact": (90, 140),
        "risk_levels": ["CRITICAL", "WARNING"],
        "why_it_works": "BOGO deals psychologically feel like a bigger win than a percentage discount. Customers perceive double the value, accelerating clearance.",
        "implementation_steps": [
            "Set up a Buy 1 Take 1 display near the entrance",
            "Clearly label items with BOGO signage",
            "Limit offer to 3 days to maintain urgency",
            "Brief all staff to actively recommend the deal",
            "Post the offer on social media with a countdown timer",
        ],
    },
    # ── WARNING-level strategies (proactive promotional efforts) ──
    {
        "id": "bundle_deal",
        "name": "Bundle & Save Deal",
        "priority": 4,
        "discount_range": (10, 20),
        "duration_days": 7,
        "conditions": ["slow_moving", "high_stock"],
        "expected_impact": (25, 40),
        "risk_levels": ["WARNING"],
        "why_it_works": "Bundling leverages the popularity of fast-moving items to pull slower ones. Customers perceive greater value in bundles, increasing basket size.",
        "implementation_steps": [
            "Bundle this product with a fast-moving complementary item",
            "Offer the bundle at the combined discounted price",
            "Place a 'Frequently Bought Together' label near the display",
            "Train staff to suggest the bundle when customers buy either item",
            "Feature the bundle in your social media story this week",
        ],
    },
    {
        "id": "weekend_flash_promo",
        "name": "Weekend Flash Promo",
        "priority": 5,
        "discount_range": (10, 15),
        "duration_days": 3,
        "conditions": ["slow_moving"],
        "expected_impact": (20, 35),
        "risk_levels": ["WARNING"],
        "why_it_works": "Weekend shoppers are impulse-driven. A short 2-3 day discount window during peak foot traffic maximizes conversions without prolonged margin erosion.",
        "implementation_steps": [
            "Schedule the promo for Friday through Sunday only",
            "Create eye-catching weekend promo signage",
            "Announce on social media by Thursday evening",
            "Place the product at eye-level or near checkout counter",
            "Track weekend sales vs. normal weekend baseline",
        ],
    },
    {
        "id": "cross_sell_pairing",
        "name": "Cross-Sell Pairing Discount",
        "priority": 6,
        "discount_range": (5, 12),
        "duration_days": 7,
        "conditions": ["slow_moving", "complementary_products"],
        "expected_impact": (15, 30),
        "risk_levels": ["WARNING"],
        "why_it_works": "Pairing a slow-mover with a related fast-seller nudges customers toward an unplanned purchase. The small discount feels like a reward.",
        "implementation_steps": [
            "Identify the top-selling product in the same category",
            "Offer a modest discount when both items are purchased together",
            "Place a shelf tag: 'Pair with [fast-mover] and save!'",
            "Train cashiers to suggest the pairing at checkout",
            "Run for 7 days and compare paired vs. standalone sales",
        ],
    },
    {
        "id": "loyalty_push",
        "name": "Loyalty Program Push",
        "priority": 7,
        "discount_range": (5, 15),
        "duration_days": 14,
        "conditions": ["slow_moving", "moderate_stock"],
        "expected_impact": (15, 30),
        "risk_levels": ["WARNING"],
        "why_it_works": "Returning customers have lower acquisition cost and higher conversion rates. Exclusive discounts make them feel valued and increase loyalty.",
        "implementation_steps": [
            "Offer the loyalty discount for returning customers only",
            "Send targeted messages to past buyers of this product category",
            "Create an exclusive member-only promo card in-store",
            "Track uplift through transaction history for 14 days",
            "Follow up with a second-purchase incentive after the promo ends",
        ],
    },
    # ── MONITOR-level strategies (low-urgency awareness & observation) ──
    {
        "id": "seasonal_spotlight",
        "name": "Seasonal Spotlight Feature",
        "priority": 8,
        "discount_range": (0, 8),
        "duration_days": 7,
        "conditions": ["seasonal", "low_priority"],
        "expected_impact": (8, 18),
        "risk_levels": ["MONITOR"],
        "why_it_works": "Seasonal positioning creates relevance. Even without discounts, featuring a product as a seasonal pick increases customer interest and trial.",
        "implementation_steps": [
            "Create a 'Seasonal Pick' shelf label or display area",
            "Feature the product in a social media post with seasonal context",
            "No heavy discount needed — focus on visibility and relevance",
            "Run for 1 week to assess customer response",
            "Review sales data after the campaign to decide next steps",
        ],
    },
    {
        "id": "social_media_awareness",
        "name": "Social Media Awareness Push",
        "priority": 9,
        "discount_range": (0, 5),
        "duration_days": 7,
        "conditions": ["low_priority"],
        "expected_impact": (5, 12),
        "risk_levels": ["MONITOR"],
        "why_it_works": "Low-cost social media posts keep the product visible to potential buyers without cutting into margins. Great for building awareness.",
        "implementation_steps": [
            "Post a product highlight on Facebook or Instagram",
            "Include a photo of the product with a brief benefit description",
            "No discount required — focus on awareness and engagement",
            "Monitor post engagement (likes, shares, comments) for 7 days",
            "Consider a small weekend discount only if engagement is high",
        ],
    },
    {
        "id": "in_store_visibility",
        "name": "In-Store Visibility Boost",
        "priority": 10,
        "discount_range": (0, 0),
        "duration_days": 14,
        "conditions": ["low_priority"],
        "expected_impact": (3, 10),
        "risk_levels": ["MONITOR"],
        "why_it_works": "Simply moving a product to a more visible shelf position or adding a clean label can increase sales by 5-10% with zero cost.",
        "implementation_steps": [
            "Relocate the product to eye-level or end-cap display",
            "Add a clean, well-printed product label or info card",
            "No discount needed — this is a zero-cost visibility play",
            "Monitor weekly sales for 2 weeks after repositioning",
            "If no improvement, consider a mild promotional strategy",
        ],
    },
]

# ============================================================================
# FEATURE ENGINEERING CONSTANTS
# ============================================================================
FEATURE_CONFIG: Dict[str, int] = {
    "lookback_days": 90,  # Historical data window
    "forecast_horizon": 30,  # Predict next 30 days
    "seasonality_window": 7,  # Weekly seasonality
    "min_transactions": 3,  # Minimum transactions for feature calculation
    # --- Sales forecast (ml/recommendation_model.py) ---
    # The model sees a product's sales in the 60 days BEFORE a reference day and
    # predicts the units sold in the 30 days FROM that day. Because the answer lies
    # entirely after the inputs, nothing the model is asked to predict is fed to it.
    "feature_window_days": 60,
    "training_step_days": 7,     # one training example per product per week of history
    # A stretch with no sales at all for longer than this is missing data, not zero
    # demand (the store was not recording). Training skips windows that contain one.
    "max_store_gap_days": 3,
    "min_training_samples": 60,  # too few examples to learn from below this
}

# The inputs of the sales forecast, in the order the model receives them.
# Built by DataProcessor.forecast_features(); a saved model remembers this list and
# is retrained when it changes.
FORECAST_FEATURE_NAMES = [
    "units_last_7",          # units sold in the last 7 days
    "units_last_30",         # units sold in the last 30 days
    "units_prev_30",         # units sold in the 30 days before that
    "sale_days_last_30",     # days with at least one sale, last 30 days
    "sale_days_60",          # days with at least one sale, whole window
    "days_since_last_sale",  # 0 = sold yesterday; 60 = no sale in the window
    "sales_trend",           # (last 30 - previous 30) / (previous 30 + 1)
    "daily_units_std",       # how uneven daily sales are
    "unit_price",
    "markup_percentage",
    "product_age_days",      # how much of the window the product existed (up to 60)
    "category_id",
]

# The same inputs in everyday words, for the "What does the system look at?" list on
# the Recommendations page (model-info sends them with the model's weight for each).
# Written for store staff, not programmers: no "trend", "markup" or "variance".
FORECAST_FEATURE_LABELS: Dict[str, str] = {
    "units_last_7": "How many were sold in the last 7 days",
    "units_last_30": "How many were sold in the last 30 days",
    "units_prev_30": "How many were sold the month before that",
    "sale_days_last_30": "On how many days it sold, last 30 days",
    "sale_days_60": "On how many days it sold, last 60 days",
    "days_since_last_sale": "How long since it was last sold",
    "sales_trend": "Whether its sales are going up or down",
    "daily_units_std": "Whether it sells a little every day or all at once",
    "unit_price": "Its selling price",
    "markup_percentage": "How much profit is added on top of its cost",
    "product_age_days": "How new the product is",
    "category_id": "What kind of product it is",
}

# ============================================================================
# API CONFIGURATION
# ============================================================================
API_CONFIG: Dict[str, Any] = {
    "debug": False,  # Disable debug mode in production
    "host": "127.0.0.1",
    "port": 5000,
    "threaded": True,
}

# ============================================================================
# MODEL FILE PATHS
# ============================================================================
MODEL_PATHS: Dict[str, str] = {
    "models_dir": os.path.join(os.path.dirname(__file__), "models"),
    "scaler": os.path.join(os.path.dirname(__file__), "models", "scaler.pkl"),
    "model": os.path.join(os.path.dirname(__file__), "models", "rf_model.pkl"),
    "feature_importance": os.path.join(
        os.path.dirname(__file__), "models", "feature_importance.pkl"
    ),
}

# ============================================================================
# LOGGING CONFIGURATION
# ============================================================================
LOG_CONFIG: Dict[str, Any] = {
    "log_level": "INFO",
    "log_file": os.path.join(os.path.dirname(__file__), "logs", "ml.log"),
    "max_bytes": 10485760,  # 10MB
    "backup_count": 5,
}

# ============================================================================
# RISK SCORE INTERPRETATION
# ============================================================================
RISK_LEVELS: Dict[str, Dict[str, Any]] = {
    "CRITICAL": {"min": 0.70, "max": 1.0, "color": "#ef4444", "action": "immediate"},
    "WARNING": {"min": 0.50, "max": 0.70, "color": "#f59e0b", "action": "urgent"},
    "MONITOR": {"min": 0.30, "max": 0.50, "color": "#3b82f6", "action": "monitor"},
    "LOW": {"min": 0.0, "max": 0.30, "color": "#10b981", "action": "track"},
}

# ============================================================================
# CONFIDENCE SCORE LABELS
# ============================================================================
CONFIDENCE_LEVELS: Dict[str, Dict[str, Any]] = {
    "HIGH": {"min": 0.80, "label": "High Confidence"},
    "MEDIUM": {"min": 0.60, "label": "Medium Confidence"},
    "LOW": {"min": 0.0, "label": "Low Confidence"},
}

# ============================================================================
# EXPORT PREDICTION TIME INTERVALS
# ============================================================================
EXPORT_INTERVALS: Dict[str, int] = {
    "daily": 1,
    "weekly": 7,
    "monthly": 30,
}
