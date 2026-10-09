"""
The sales forecast is trained without being shown its own answer.

Checks, with a made-up sales history and no database:
  - the forecast inputs only use sales from before the day of the forecast
  - a training example's answer (units sold in the next 30 days) never feeds its inputs
  - stretches where the store recorded nothing are not used for training
  - no forecast is made while recent records have a hole, or for a product with no sales
  - confidence is how closely the forest's trees agree, and lowest when there is no forecast

Run from the project folder:  python tests/forecast_training.py
"""
from datetime import date, timedelta
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ml import data_service
from ml.data_service import DataProcessor
from ml.ml_config import FEATURE_CONFIG, FORECAST_FEATURE_NAMES
from ml.recommendation_model import NO_FORECAST_CONFIDENCE, RecommendationModel

WINDOW = FEATURE_CONFIG["feature_window_days"]
HORIZON = FEATURE_CONFIG["forecast_horizon"]
real_query = data_service.DatabaseConnector.execute_query


def with_rows(sales, products, call):
    """Run `call` with the database queries answered from the given rows."""
    data_service.DatabaseConnector.execute_query = staticmethod(
        lambda query, params=None, fetch_one=False: sales if "transaction_items" in query or "FROM sales" in query else products
    )
    try:
        return call()
    finally:
        data_service.DatabaseConnector.execute_query = real_query


# ── Inputs for one product on one day ────────────────────────────────────────
DAY = date(2026, 3, 1)
product = {"price": 100, "cost_price": 50, "category_id": 3, "created_at": "2025-01-01 08:00:00"}
units = {
    DAY - timedelta(days=1): 4, DAY - timedelta(days=5): 2, DAY - timedelta(days=20): 10, DAY - timedelta(days=45): 6,
    # On the day, after it, and before the 60-day window: none of these may be counted
    DAY: 99, DAY + timedelta(days=3): 99, DAY - timedelta(days=61): 99,
}
inputs = DataProcessor.forecast_features(units, DAY, product)
assert list(inputs) == FORECAST_FEATURE_NAMES
assert inputs["units_last_7"] == 6 and inputs["units_last_30"] == 16 and inputs["units_prev_30"] == 6
assert inputs["sale_days_last_30"] == 3 and inputs["sale_days_60"] == 4 and inputs["days_since_last_sale"] == 0
assert abs(inputs["sales_trend"] - 10 / 7) < 1e-9
assert inputs["markup_percentage"] == 100 and inputs["product_age_days"] == WINDOW

# A product that did not exist yet has no inputs; one whose recorded creation date is
# later than its first sale is aged from that sale
assert DataProcessor.forecast_features({}, DAY, {**product, "created_at": "2026-03-05"}) is None
assert DataProcessor.forecast_features({}, DAY, {**product, "created_at": "0000-00-00 00:00:00"}) is None
late = DataProcessor.forecast_features({DAY - timedelta(days=10): 3}, DAY, {**product, "created_at": "2026-04-01"})
assert late["product_age_days"] == 10

# ── Training examples from a history ─────────────────────────────────────────
# 150 unbroken days (product 1 sells 2 a day, product 2 sells 1 every other day),
# then silence, then one stray sale 50 days later.
START = date(2026, 1, 1)
history = []
for i in range(150):
    day = START + timedelta(days=i)
    history.append({"product_id": 1, "day": day, "units": 2})
    if i % 2 == 0:
        history.append({"product_id": 2, "day": day, "units": 1})
    history.append({"product_id": 77, "day": day, "units": 5})  # a product deleted since
history.append({"product_id": 1, "day": START + timedelta(days=200), "units": 3})
products = [
    {"id": 1, "price": 100, "cost_price": 60, "category_id": 1, "created_at": "2025-06-01"},
    {"id": 2, "price": 50, "cost_price": 30, "category_id": 2, "created_at": "2025-06-01"},
    {"id": 3, "price": 10, "cost_price": 5, "category_id": 2, "created_at": START + timedelta(days=300)},  # not created yet
]
today = START + timedelta(days=260)
examples = with_rows(history, products, lambda: DataProcessor.build_forecast_training_set(today))

refs = sorted(examples["ref"].unique())
assert refs[0] == START + timedelta(days=WINDOW)
assert all((b - a).days == FEATURE_CONFIG["training_step_days"] for a, b in zip(refs, refs[1:]))
# Every answer period lies inside the unbroken records; the stray sale creates no example
assert refs[-1] + timedelta(days=HORIZON - 1) <= START + timedelta(days=149)
assert set(examples["product_id"]) == {1, 2}
assert (examples[examples["product_id"] == 1]["target"] == 2 * HORIZON).all()
assert (examples[examples["product_id"] == 2]["target"] == HORIZON / 2).all()
assert "target" not in FORECAST_FEATURE_NAMES

# The no-leak property itself: multiply every sale in the last answer period by 10.
# The answers must change and not one input may move.
inflated = [dict(row, units=row["units"] * 10) if row["day"] >= refs[-1] else row for row in history]
changed = with_rows(inflated, products, lambda: DataProcessor.build_forecast_training_set(today))
before = examples[examples["ref"] == refs[-1]].sort_values("product_id")
after = changed[changed["ref"] == refs[-1]].sort_values("product_id")
assert (after["target"].to_numpy() == before["target"].to_numpy() * 10).all()
assert (after[FORECAST_FEATURE_NAMES].to_numpy() == before[FORECAST_FEATURE_NAMES].to_numpy()).all()

# ── Holes in the store's records ─────────────────────────────────────────────
TODAY = date(2026, 6, 1)
daily = [TODAY - timedelta(days=i) for i in range(1, 70)]


def records_complete(days):
    return with_rows([{"day": d} for d in days], [], lambda: DataProcessor.recent_records_complete(TODAY))


assert records_complete(daily)
assert records_complete([d for d in daily if d.weekday() != 6])                     # closed on Sundays
assert not records_complete([d for d in daily if not 20 <= (TODAY - d).days <= 27])  # a week missing
assert not records_complete(daily[:21])                                             # in use for 3 weeks only
assert not records_complete([])


# ── When a forecast is made ──────────────────────────────────────────────────
class FixedModel:
    def predict(self, X):
        return [12.5] * len(X)


class NoScaling:
    def transform(self, X):
        return X


model = RecommendationModel.__new__(RecommendationModel)
model.model, model.scaler, model._records_check = FixedModel(), NoScaling(), None
selling = DataProcessor.forecast_features({TODAY - timedelta(days=3): 5}, TODAY, product)
not_selling = DataProcessor.forecast_features({}, TODAY, product)

original = DataProcessor.recent_records_complete
try:
    DataProcessor.recent_records_complete = staticmethod(lambda today=None: True)
    assert model.forecast_units(selling) == 12.5
    assert model.forecast_units(not_selling) is None   # no sale in 60 days: nothing to go on
    assert model.forecast_units(None) is None          # too new to have inputs

    model._records_check = None
    DataProcessor.recent_records_complete = staticmethod(lambda today=None: False)
    assert model.forecast_units(selling) is None and not model.forecast_in_use()

    model.model, model._records_check = None, None     # not trained
    DataProcessor.recent_records_complete = staticmethod(lambda today=None: True)
    assert model.forecast_units(selling) is None and not model.forecast_in_use()

    # ── How far a forecast is trusted ────────────────────────────────────────
    # Confidence is how closely the forest's trees agree, and the lowest score
    # when the estimate is not the model's at all
    class Tree:
        def __init__(self, units):
            self.units = units

        def predict(self, X):
            return [self.units] * len(X)

    class Forest:
        def __init__(self, *units):
            self.estimators_ = [Tree(u) for u in units]

    def confidence(forest, inputs=selling):
        model.model, model._records_check = forest, None
        return model.get_prediction_confidence(inputs)

    assert confidence(Forest(20, 20, 20, 20)) == 0.95                    # full agreement, capped
    assert abs(confidence(Forest(18, 22, 18, 22)) - (1 - 2 / 21)) < 1e-9  # spread 2 around 20 units
    assert confidence(Forest(5, 40, 5, 40)) < confidence(Forest(18, 22, 18, 22))
    assert confidence(Forest(0, 90, 0, 90)) == NO_FORECAST_CONFIDENCE    # never below the lowest score
    assert confidence(Forest(0.4, 0.6, 0.4, 0.6)) > 0.9                  # tiny forecast, tiny spread
    assert confidence(Forest(20, 20), not_selling) == NO_FORECAST_CONFIDENCE
    assert confidence(Forest(20, 20), None) == NO_FORECAST_CONFIDENCE
    assert confidence(None) == NO_FORECAST_CONFIDENCE                    # not trained

    DataProcessor.recent_records_complete = staticmethod(lambda today=None: False)
    assert confidence(Forest(20, 20)) == NO_FORECAST_CONFIDENCE          # records have a hole
finally:
    DataProcessor.recent_records_complete = original

print("PASS: sales forecast is trained without its own answer and only used on unbroken records")
print("PASS: confidence is the trees' agreement, and lowest when the estimate is not the model's")
