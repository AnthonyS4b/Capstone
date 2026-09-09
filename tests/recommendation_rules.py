from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ml.recommendation_model import RecommendationModel
from ml import api_server


def build_model(strategies):
    model = RecommendationModel.__new__(RecommendationModel)
    model._load_db_strategies = lambda: strategies
    return model


def features(price, cost, *, expiring=False, expired=False):
    return {
        "monthly_sales_velocity": 0,
        "current_stock": 10,
        "markup_percentage": ((price - cost) / cost) * 100,
        "unit_price": price,
        "cost_price": cost,
        "is_critical_expiry": expiring,
        "is_expired": expired,
    }


discount_strategy = {
    "id": "loyalty_push",
    "name": "Loyalty Push",
    "priority": 7,
    "discount_range": (5, 15),
    "duration_days": 14,
    "conditions": ["slow_moving", "moderate_stock"],
    "risk_levels": ["WARNING"],
    "expected_impact": (15, 30),
}

model = build_model([discount_strategy])
result = model.recommend_strategy(1, {"unit": "piece"}, features(92.5, 130), 0.8, "CRITICAL")
assert result[0]["strategy_id"] == "price_cost_review"
assert result[0]["recommended_discount"] == 0

result = model.recommend_strategy(2, {"unit": "piece"}, features(210, 155), 0.8, "CRITICAL")
assert 5 <= result[0]["recommended_discount"] <= ((210 - 155) / 210) * 100

result = model.recommend_strategy(
    3,
    {"unit": "piece"},
    features(210, 155, expired=True),
    1.0,
    "CRITICAL",
)
assert result[0]["strategy_id"] == "expired_stock_removal"

# The direct-database fallback must apply the same margin protection as the ML path.
original_loader = api_server._load_strategies_from_db
api_server._load_strategies_from_db = lambda: [discount_strategy]
try:
    result = api_server._pick_strategies_from_db(
        is_critical=False,
        is_slow=True,
        is_high_stock=False,
        markup_pct=((92.5 - 130) / 130) * 100,
        risk_level="CRITICAL",
        is_packaged=True,
        price=92.5,
        cost=130,
        current_stock=10,
    )
    assert result[0]["strategy_id"] == "price_cost_review"
finally:
    api_server._load_strategies_from_db = original_loader

print("PASS: recommendation margin and expiry safety rules")
