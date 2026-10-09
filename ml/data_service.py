"""
Unified Data Service for Database Operations and Feature Engineering.

Combines database connection management and data preprocessing functionality.
Provides context-managed database connections and utilities for data extraction,
cleaning, and feature engineering for ML models.
"""

import logging
from contextlib import contextmanager
from datetime import date, datetime, timedelta
from typing import Any, Optional, List, Dict, Set, Tuple

# pyrefly: ignore [missing-import]
import numpy as np
# pyrefly: ignore [missing-import]
import pandas as pd
# pyrefly: ignore [missing-import]
import pymysql
# pyrefly: ignore [missing-import]
import pymysql.cursors

try:
    from .ml_config import DB_CONFIG, FEATURE_CONFIG, FORECAST_FEATURE_NAMES, THRESHOLDS
except ImportError:
    from ml_config import DB_CONFIG, FEATURE_CONFIG, FORECAST_FEATURE_NAMES, THRESHOLDS

logger = logging.getLogger(__name__)


# ============================================================================
# DATABASE CONNECTOR
# ============================================================================


class DatabaseConnector:
    """
    Database connection manager with built-in error handling and resource management.
    
    All methods are static for singleton-like behavior without instantiation.
    Uses context managers to ensure proper connection cleanup.
    """

    @staticmethod
    @contextmanager
    def get_connection():
        """
        Context manager for database connections.
        
        Automatically handles connection creation and cleanup.
        
        Usage:
            with DatabaseConnector.get_connection() as conn:
                with conn.cursor() as cursor:
                    cursor.execute(query)
        
        Yields:
            pymysql.Connection: Database connection object
            
        Raises:
            pymysql.Error: Database connection error
        """
        conn = None
        try:
            conn = pymysql.connect(
                host=DB_CONFIG["host"],
                user=DB_CONFIG["user"],
                password=DB_CONFIG["password"],
                database=DB_CONFIG["database"],
                port=DB_CONFIG["port"],
                charset="utf8mb4",
                cursorclass=pymysql.cursors.DictCursor,
            )
            yield conn
        except pymysql.Error as e:
            logger.error(f"Database connection error: {e}")
            raise
        finally:
            if conn:
                conn.close()

    @staticmethod
    def execute_query(
        query: str, params: Optional[tuple] = None, fetch_one: bool = False
    ) -> Any:
        """
        Execute SELECT query and return results.
        
        Args:
            query: SQL SELECT query string
            params: Optional tuple of parameters for parameterized query
            fetch_one: If True, return single row; else return all rows
            
        Returns:
            Dict or List of Dicts with query results
            
        Raises:
            pymysql.Error: Query execution error
        """
        try:
            with DatabaseConnector.get_connection() as conn:
                with conn.cursor() as cursor:
                    if params:
                        cursor.execute(query, params)
                    else:
                        cursor.execute(query)

                    if fetch_one:
                        return cursor.fetchone()
                    return cursor.fetchall()
        except pymysql.Error as e:
            logger.error(f"Query execution error: {e}")
            raise

    @staticmethod
    def execute_insert_update(query: str, params: Optional[tuple] = None) -> int:
        """
        Execute INSERT/UPDATE/DELETE query.
        
        Args:
            query: SQL INSERT/UPDATE/DELETE query string
            params: Optional tuple of parameters for parameterized query
            
        Returns:
            int: Number of affected rows
            
        Raises:
            pymysql.Error: Query execution error
        """
        conn = None
        try:
            with DatabaseConnector.get_connection() as conn:
                with conn.cursor() as cursor:
                    if params:
                        cursor.execute(query, params)
                    else:
                        cursor.execute(query)
                    conn.commit()
                    return cursor.rowcount
        except pymysql.Error as e:
            logger.error(f"Insert/Update error: {e}")
            if conn:
                conn.rollback()
            raise

    @staticmethod
    def execute_batch_insert(query: str, data_list: List[tuple]) -> int:
        """
        Batch insert multiple records with a single transaction.
        
        Args:
            query: SQL INSERT query string with placeholders
            data_list: List of tuples containing values to insert
            
        Returns:
            int: Number of affected rows
            
        Raises:
            pymysql.Error: Batch insert error
        """
        conn = None
        try:
            with DatabaseConnector.get_connection() as conn:
                with conn.cursor() as cursor:
                    cursor.executemany(query, data_list)
                    conn.commit()
                    return cursor.rowcount
        except pymysql.Error as e:
            logger.error(f"Batch insert error: {e}")
            if conn:
                conn.rollback()
            raise


# ============================================================================
# DATA PROCESSOR
# ============================================================================


class DataProcessor:
    """Data extraction, cleaning, and feature engineering utilities."""

    # ========================================================================
    # DATA FETCH METHODS
    # ========================================================================

    @staticmethod
    def get_product_sales_data(
        product_id: int, days_back: int = FEATURE_CONFIG["lookback_days"]
    ) -> pd.DataFrame:
        """
        Fetch sales history for a product.

        Args:
            product_id: Product ID to fetch sales for
            days_back: Number of days of historical data to fetch (default: 90)

        Returns:
            pd.DataFrame: Sales data with columns: transaction_id, product_id,
                         quantity, price, sale_date, current_stock,
                         expiration_date, cost_price, unit_price
        """
        query = """
            SELECT 
                ti.transaction_id,
                ti.product_id,
                ti.quantity,
                ti.price,
                s.created_at as sale_date,
                p.stock as current_stock,
                p.expiration_date,
                p.cost_price,
                p.price as unit_price
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            JOIN sales s ON t.id = s.transaction_id
            JOIN products p ON ti.product_id = p.id
            WHERE ti.product_id = %s
            AND s.created_at >= DATE_SUB(NOW(), INTERVAL %s DAY)
            AND s.status = 'completed'
            ORDER BY s.created_at ASC
        """
        try:
            data = DatabaseConnector.execute_query(query, (product_id, days_back))
            if not data:
                return pd.DataFrame()
            df = pd.DataFrame(data)
            # DECIMAL columns arrive as decimal.Decimal, which cannot be mixed with
            # floats in pandas maths; quantity is decimal kilograms for Per Kilo products
            for col in ("quantity", "price", "current_stock", "cost_price", "unit_price"):
                if col in df.columns:
                    df[col] = pd.to_numeric(df[col], errors="coerce").fillna(0).astype(float)
            return df
        except Exception as e:
            logger.error(f"Error fetching sales data for product {product_id}: {e}")
            return pd.DataFrame()

    @staticmethod
    def get_inventory_snapshots(
        product_id: int, days_back: int = FEATURE_CONFIG["lookback_days"]
    ) -> pd.DataFrame:
        """
        Get historical inventory levels from inventory_history table.

        Args:
            product_id: Product ID to fetch inventory for
            days_back: Number of days of historical data (default: 90)

        Returns:
            pd.DataFrame: Inventory history with columns: created_at, action,
                         changes, current_stock
        """
        query = """
            SELECT 
                ih.created_at,
                ih.action,
                ih.changes,
                p.stock as current_stock
            FROM inventory_history ih
            JOIN products p ON ih.product_id = p.id
            WHERE ih.product_id = %s
            AND ih.created_at >= DATE_SUB(NOW(), INTERVAL %s DAY)
            ORDER BY ih.created_at DESC
        """
        try:
            data = DatabaseConnector.execute_query(query, (product_id, days_back))
            return pd.DataFrame(data) if data else pd.DataFrame()
        except Exception as e:
            logger.error(f"Error fetching inventory snapshots: {e}")
            return pd.DataFrame()

    @staticmethod
    def get_all_active_products() -> List[Tuple[int, Dict[str, Any]]]:
        """
        Get all active non-archived products from database.

        Returns:
            List of (product_id, product_data_dict) tuples for active products
        """
        query = """
            SELECT 
                id, name, category_id, price, cost_price, 
                stock, expiration_date, created_at
            FROM products
            WHERE deleted_at IS NULL
            AND archived_at IS NULL
            AND stock > 0
        """
        try:
            products = DatabaseConnector.execute_query(query)
            return [(p["id"], p) for p in products] if products else []
        except Exception as e:
            logger.error(f"Error fetching active products: {e}")
            return []

    # ========================================================================
    # METRIC CALCULATION METHODS
    # ========================================================================

    @staticmethod
    def calculate_sales_metrics(sales_df: pd.DataFrame) -> Dict[str, float]:
        """
        Calculate key sales metrics from sales data.

        Args:
            sales_df: DataFrame with sales transaction data

        Returns:
            Dict with metrics: total_units_sold, total_revenue, avg_daily_sales,
                               monthly_sales_velocity, total_sold_last_30d, 
                               sales_trend, min_price, max_price, std_dev
        """
        if sales_df.empty:
            return {
                "total_units_sold": 0,
                "total_revenue": 0,
                "avg_daily_sales": 0,
                "monthly_sales_velocity": 0,
                "total_sold_last_30d": 0,
                "sales_trend": 0,
                "min_price": 0,
                "max_price": 0,
                "std_dev": 0,
            }

        # Work on a copy so callers do not get their DataFrame mutated.
        sales_df = sales_df.copy()
        sales_df["sale_date"] = pd.to_datetime(sales_df["sale_date"])
        sales_df["revenue"] = sales_df["price"] * sales_df["quantity"]
        today = pd.Timestamp.now().normalize()
        thirty_days_ago = today - pd.Timedelta(days=30)

        # Aggregate by date
        daily_sales = (
            sales_df.groupby(sales_df["sale_date"].dt.date)
            .agg({"quantity": "sum", "revenue": "sum"})
            .reset_index()
        )

        if daily_sales.empty:
            return {
                "total_units_sold": 0,
                "total_revenue": 0,
                "avg_daily_sales": 0,
                "monthly_sales_velocity": 0,
                "total_sold_last_30d": 0,
                "sales_trend": 0,
                "min_price": 0,
                "max_price": 0,
                "std_dev": 0,
            }

        total_units = daily_sales["quantity"].sum()
        total_revenue = daily_sales["revenue"].sum()
        
        # Calculate last 30 days total
        last_30d_df = sales_df[sales_df["sale_date"] >= thirty_days_ago]
        total_30d = int(last_30d_df["quantity"].sum())

        # Monthly velocity: average per month over lookback period (90 days)
        # Using 3.0 as denominator for 90 days (standardized window)
        lookback_months = FEATURE_CONFIG["lookback_days"] / 30.0
        monthly_velocity = float(total_units / lookback_months)

        # Calculate trend: recent vs old
        mid_point = len(daily_sales) // 2
        recent_avg = (
            daily_sales["quantity"].iloc[mid_point:].mean()
            if mid_point < len(daily_sales)
            else 0
        )
        old_avg = (
            daily_sales["quantity"].iloc[:mid_point].mean() if mid_point > 0 else 0
        )
        trend = ((recent_avg - old_avg) / (old_avg + 0.1)) * 100

        return {
            "total_units_sold": int(total_units),
            "total_revenue": float(total_revenue),
            "avg_daily_sales": float(total_units / FEATURE_CONFIG["lookback_days"]),
            "monthly_sales_velocity": monthly_velocity,
            "total_sold_last_30d": total_30d,
            "sales_trend": float(trend),
            "min_price": float(sales_df["price"].min()),
            "max_price": float(sales_df["price"].max()),
            "std_dev": float(daily_sales["quantity"].std())
            if len(daily_sales) > 1
            else 0,
        }

    @staticmethod
    def calculate_expiry_metrics(product_data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Calculate expiration-related metrics.

        Args:
            product_data: Product data dict with expiration_date

        Returns:
            Dict with metrics: days_until_expiry, is_expired,
                              is_critical_expiry, shelf_life_days
        """
        if not product_data or not product_data.get("expiration_date"):
            return {
                "days_until_expiry": 999,
                "is_expired": False,
                "is_critical_expiry": False,
                "shelf_life_days": 0,
            }

        try:
            # Expiration values are calendar dates. Normalize both operands so
            # a product remains valid throughout its expiration date.
            expiry_date = pd.to_datetime(product_data["expiration_date"]).normalize()
            today = pd.Timestamp.now().normalize()
            days_remaining = (expiry_date - today).days

            return {
                "days_until_expiry": max(0, days_remaining),
                "is_expired": days_remaining < 0,
                "is_critical_expiry": days_remaining <= THRESHOLDS["critical_stock_days"],
                "shelf_life_days": days_remaining,
            }
        except Exception:
            return {
                "days_until_expiry": 999,
                "is_expired": False,
                "is_critical_expiry": False,
                "shelf_life_days": 0,
            }

    # ========================================================================
    # SALES FORECAST INPUTS
    # ========================================================================
    # The forecast answers "how many units will this product sell in the next 30
    # days?" from what was known on a given day: its sales in the 60 days before
    # that day, its price and margin, and how long it has existed. The same
    # function builds the inputs for training (a day in the past, where the answer
    # is known) and for a live forecast (today), so the two can never drift apart.

    @staticmethod
    def _as_date(value: Any) -> Optional[date]:
        """A calendar date from a date, datetime or 'YYYY-MM-DD ...' text; None if unusable."""
        if value is None or value == "":
            return None
        try:
            stamp = pd.Timestamp(value)
            return None if pd.isna(stamp) else stamp.date()
        except Exception:
            return None

    @staticmethod
    def units_by_day(sales_df: pd.DataFrame) -> Dict[date, float]:
        """Units sold per calendar day, from the rows of get_product_sales_data()."""
        if sales_df is None or sales_df.empty:
            return {}
        days = pd.to_datetime(sales_df["sale_date"]).dt.date
        return {day: float(units) for day, units in sales_df.groupby(days)["quantity"].sum().items()}

    @staticmethod
    def forecast_features(
        units_by_day: Dict[date, float], as_of: date, product: Dict[str, Any]
    ) -> Optional[Dict[str, float]]:
        """
        The forecast inputs for one product as they were known on `as_of`.

        Only sales strictly before `as_of` are used. Returns None when the product
        did not exist yet on that day (no forecast can be made for it).

        Args:
            units_by_day: units the product sold on each calendar day
            as_of: the day the forecast is made from
            product: needs price, cost_price, category_id and created_at
        """
        window = FEATURE_CONFIG["feature_window_days"]
        half = window // 2
        # units[0] is the day before as_of, units[window - 1] the oldest day in the window
        units = [float(units_by_day.get(as_of - timedelta(days=i), 0.0)) for i in range(1, window + 1)]
        sold_days = [i for i, u in enumerate(units) if u > 0]

        # The product has to exist by as_of: created before it, or already selling
        created = DataProcessor._as_date(product.get("created_at"))
        first_known = as_of - timedelta(days=sold_days[-1] + 1) if sold_days else None
        if created is not None and created < as_of:
            first_known = min(created, first_known) if first_known else created
        if first_known is None:
            return None

        last_30 = sum(units[:half])
        prev_30 = sum(units[half:])
        price = float(product.get("price") or 0)
        recorded_cost = float(product.get("cost_price") or 0)
        # Same stand-in as extract_features() when no cost was recorded
        cost = recorded_cost if recorded_cost > 0 else (price * 0.7 if price > 0 else 1.0)

        values = {
            "units_last_7": sum(units[:7]),
            "units_last_30": last_30,
            "units_prev_30": prev_30,
            "sale_days_last_30": sum(1 for u in units[:half] if u > 0),
            "sale_days_60": len(sold_days),
            "days_since_last_sale": sold_days[0] if sold_days else window,
            "sales_trend": (last_30 - prev_30) / (prev_30 + 1.0),
            "daily_units_std": float(np.std(units)),
            "unit_price": price,
            "markup_percentage": ((price - cost) / cost) * 100.0,
            "product_age_days": min(window, (as_of - first_known).days),
            "category_id": product.get("category_id") or 0,
        }
        return {name: float(values[name]) for name in FORECAST_FEATURE_NAMES}

    @staticmethod
    def _store_was_recording(
        store_days: Set[date], start: date, end: date, max_gap: int, known_until: Optional[date] = None
    ) -> bool:
        """
        False when [start, end) touches a run of more than max_gap days with no sale at all.

        A quiet run is measured in full even where it crosses the edge of the period:
        records that stop two days before `end` and stay silent for weeks are a hole,
        not a two-day closure, and would understate the sales in those last days.
        Days from `known_until` on have not happened yet and are never counted as quiet.
        """
        # Quiet days leading up to the period count towards a run that continues into it
        quiet = 0
        while quiet <= max_gap and (start - timedelta(days=quiet + 1)) not in store_days:
            quiet += 1

        day = start
        while day < end:
            quiet = 0 if day in store_days else quiet + 1
            if quiet > max_gap:
                return False
            day += timedelta(days=1)

        # ... and a run still open at the end is followed past it
        while quiet > 0 and day not in store_days and (known_until is None or day < known_until):
            quiet += 1
            if quiet > max_gap:
                return False
            day += timedelta(days=1)
        return True

    @staticmethod
    def recent_records_complete(today: Optional[date] = None) -> bool:
        """
        Whether the store has unbroken sales records for the 60 days up to today.

        The forecast is trained only on such periods. When recent records have a hole
        (the system was not in use for a while), a product's "no sales" in that hole is
        missing data rather than no demand, and a forecast made from it would mean
        nothing, so none is made until 60 unbroken days have built up again.
        """
        window = FEATURE_CONFIG["feature_window_days"]
        max_gap = FEATURE_CONFIG["max_store_gap_days"]
        today = today or datetime.now().date()
        try:
            rows = DatabaseConnector.execute_query(
                """SELECT DISTINCT DATE(created_at) AS day FROM sales
                   WHERE status = 'completed' AND created_at >= %s""",
                (today - timedelta(days=window + max_gap + 1),),
            )
        except Exception as e:
            logger.error(f"Error checking recent sales records: {e}")
            return False
        store_days = {DataProcessor._as_date(row["day"]) for row in rows or []}
        return DataProcessor._store_was_recording(
            store_days, today - timedelta(days=window), today, max_gap, known_until=today
        )

    @staticmethod
    def build_forecast_training_set(today: Optional[date] = None) -> pd.DataFrame:
        """
        Every usable training example in the sales history.

        One example is a product on a reference day in the past: the forecast inputs
        as they were known that day, and the answer (`target`), the units it then sold
        in the following 30 days. Reference days are a week apart. A reference day is
        skipped when the store has a stretch of missing sales data around it.

        Returns:
            DataFrame with the FORECAST_FEATURE_NAMES columns plus `target`, `ref`
            (the reference day) and `product_id`; empty when there is no usable history.
        """
        window = FEATURE_CONFIG["feature_window_days"]
        horizon = FEATURE_CONFIG["forecast_horizon"]
        step = FEATURE_CONFIG["training_step_days"]
        max_gap = FEATURE_CONFIG["max_store_gap_days"]
        today = today or datetime.now().date()

        try:
            rows = DatabaseConnector.execute_query(
                """SELECT ti.product_id, DATE(s.created_at) AS day, SUM(ti.quantity) AS units
                   FROM transaction_items ti
                   JOIN sales s ON s.transaction_id = ti.transaction_id
                   WHERE s.status = 'completed'
                   GROUP BY ti.product_id, DATE(s.created_at)"""
            )
            products = DatabaseConnector.execute_query(
                "SELECT id, price, cost_price, category_id, created_at FROM products"
            )
        except Exception as e:
            logger.error(f"Error loading sales history for training: {e}")
            return pd.DataFrame()
        if not rows or not products:
            return pd.DataFrame()

        units_by_product: Dict[int, Dict[date, float]] = {}
        store_days: Set[date] = set()
        for row in rows:
            day = DataProcessor._as_date(row["day"])
            if day is None:
                continue
            store_days.add(day)  # any sale, even of a since-deleted product, shows the store was recording
            units_by_product.setdefault(int(row["product_id"]), {})[day] = float(row["units"] or 0)
        if not store_days:
            return pd.DataFrame()

        samples = []
        ref = min(store_days) + timedelta(days=window)
        while ref + timedelta(days=horizon) <= today:  # the 30 answer days must all be in the past
            if DataProcessor._store_was_recording(
                store_days, ref - timedelta(days=window), ref + timedelta(days=horizon), max_gap,
                known_until=today,
            ):
                for product in products:
                    product_id = int(product["id"])
                    day_units = units_by_product.get(product_id, {})
                    sample = DataProcessor.forecast_features(day_units, ref, product)
                    if sample is None:
                        continue
                    sample["target"] = sum(
                        day_units.get(ref + timedelta(days=i), 0.0) for i in range(horizon)
                    )
                    sample["ref"] = ref
                    sample["product_id"] = product_id
                    samples.append(sample)
            ref += timedelta(days=step)

        return pd.DataFrame(samples)

    # ========================================================================
    # FEATURE EXTRACTION METHODS
    # ========================================================================

    @staticmethod
    def extract_features(
        product_id: int, product_data: Dict[str, Any]
    ) -> Optional[Dict[str, Any]]:
        """
        Extract all features needed for ML model.

        Args:
            product_id: Product ID
            product_data: Product data dictionary

        Returns:
            Feature dict compatible with ML model, or None on error
        """
        try:
            # Fetch and calculate metrics
            sales_df = DataProcessor.get_product_sales_data(product_id)
            sales_metrics = DataProcessor.calculate_sales_metrics(sales_df)
            inventory_df = DataProcessor.get_inventory_snapshots(product_id)
            expiry_metrics = DataProcessor.calculate_expiry_metrics(product_data)

            # Calculate days_in_stock — prefer date_added (user-set), fall back to created_at
            days_in_stock = 0
            date_added = product_data.get("date_added") or product_data.get("created_at") or product_data.get("product_created_at")
            created_at = date_added  # keep reference for date_added output
            if date_added:
                try:
                    if isinstance(date_added, str):
                        date_added_dt = pd.to_datetime(date_added)
                    else:
                        date_added_dt = pd.Timestamp(date_added)
                    days_in_stock = max(0, (pd.Timestamp.now() - date_added_dt).days)
                except Exception:
                    days_in_stock = 0

            price = float(product_data.get("price", 0))
            recorded_cost = float(product_data.get("cost_price", 0) or 0)
            cost = recorded_cost if recorded_cost > 0 else (price * 0.7 if price > 0 else 1.0)

            features = {
                "product_id": product_id,
                "current_stock": int(product_data.get("stock", 0)),
                "unit_price": price,
                "cost_price": cost,
                "markup_percentage": float(((price - cost) / cost) * 100),
                "total_units_sold": sales_metrics["total_units_sold"],
                "total_sold_last_30d": sales_metrics["total_sold_last_30d"],
                "total_sold_90d": sales_metrics["total_units_sold"],
                "total_revenue": sales_metrics["total_revenue"],
                "avg_daily_sales": sales_metrics["avg_daily_sales"],
                "monthly_sales_velocity": sales_metrics["monthly_sales_velocity"],
                "sales_trend": sales_metrics["sales_trend"],
                "price_volatility": sales_metrics["std_dev"],
                "days_until_expiry": expiry_metrics["days_until_expiry"],
                "is_expired": 1 if expiry_metrics["is_expired"] else 0,
                "is_critical_expiry": expiry_metrics["is_critical_expiry"],  # keep as bool for logic
                "inventory_turnover_rate": (
                    sales_metrics["avg_daily_sales"]
                    / max(int(product_data.get("stock", 1)), 1)
                ),
                "days_in_stock": days_in_stock,
                # Actual price-to-cost ratio (meaningful economic signal)
                "revenue_to_cost_ratio": price / cost,
                # Date added for ML tracking
                "date_added": str(created_at) if created_at else None,
                # Inputs of the sales forecast as known today (None for a product
                # created today). Kept apart from the figures above, which feed the rules.
                "forecast_features": DataProcessor.forecast_features(
                    DataProcessor.units_by_day(sales_df), datetime.now().date(), product_data
                ),
            }

            return features
        except Exception as e:
            logger.error(f"Error extracting features for product {product_id}: {e}")
            return None

    @staticmethod
    def prepare_feature_matrix(
        products_list: List[Tuple[int, Dict[str, Any]]]
    ) -> Tuple[Optional[np.ndarray], Optional[List[int]], Optional[List[str]]]:
        """
        Prepare feature matrix for ML model training.

        Args:
            products_list: List of (product_id, product_data_dict) tuples

        Returns:
            Tuple of (X feature matrix, product_ids list, feature_names list)
        """
        features_list = []
        product_ids = []
        feature_names = None

        for product_id, product_data in products_list:
            features = DataProcessor.extract_features(product_id, product_data)
            if features:
                if feature_names is None:
                    feature_names = [k for k in features.keys() if k not in ("product_id", "date_added", "forecast_features")]

                features_list.append([features[key] for key in feature_names])
                product_ids.append(product_id)

        if not features_list:
            return None, None, None

        X = np.array(features_list)
        return X, product_ids, feature_names
