"""Run with python -B tests/cross_sell_api.py; all writes use temporary tables."""
import sys
from pathlib import Path
from contextlib import contextmanager
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import pymysql
import ml.api_server as api
from ml.ml_config import DB_CONFIG

api.recommender = object()
with pymysql.connect(**DB_CONFIG, read_timeout=180, cursorclass=pymysql.cursors.DictCursor) as conn:
    with conn.cursor() as cursor:
        for table in ['products', 'categories', 'strategy_history', 'strategy_templates',
                      'ml_recommendation_logs', 'transaction_items', 'sales']:
            cursor.execute('SHOW CREATE TABLE ' + table)
            cursor.execute(cursor.fetchone()['Create Table'].replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', 1))
        cursor.execute("INSERT INTO categories (id,name,status) VALUES (900001,'Test Category','active')")
        for product_id, name, price, stock in [(900001, 'Slow Item', 220, 20),
                                                (900002, 'Partner', 50, 20),
                                                (900003, 'Unavailable', 60, 0)]:
            cursor.execute("""INSERT INTO products (id,name,category_id,price,stock,status,sku,barcode)
                              VALUES (%s,%s,900001,%s,%s,'active',%s,%s)""",
                           (product_id, name, price, stock, str(product_id), str(product_id)))
    conn.commit()

    @contextmanager
    def connection():
        try:
            yield conn
        finally:
            conn.rollback()

    with patch.object(api.DatabaseConnector, 'get_connection', connection):
        client = api.app.test_client()
        payload = {'product_id': 900001, 'strategy_id': 'cross_sell_pairing', 'discount_percentage': 10}
        assert client.post('/api/save-recommendation', json=payload).status_code == 400
        for partner in [900001, 900003, 999999]:
            payload['paired_product_id'] = partner
            assert client.post('/api/save-recommendation', json=payload).status_code == 400
        payload['paired_product_id'] = 900002
        saved = client.post('/api/save-recommendation', json=payload)
        assert saved.status_code == 201, saved.json
        with conn.cursor() as cursor:
            cursor.execute('SELECT price FROM products WHERE id=900001')
            assert cursor.fetchone()['price'] == 220
            cursor.execute('SELECT id,paired_product_id,discounted_price FROM strategy_history')
            history = cursor.fetchone()
            assert history['paired_product_id'] == 900002 and history['discounted_price'] == 198
        candidates = client.get('/api/pairing-products?product_id=900001')
        assert candidates.status_code == 200, candidates.json
        assert [row['id'] for row in candidates.json['products']] == [900002]
        active = client.get('/api/active-strategies')
        assert active.status_code == 200, active.json
        assert active.json['strategies'][0]['paired_product_name'] == 'Partner'
        assert client.post('/api/save-recommendation', json=payload).status_code == 409
        cancelled = client.post('/api/cancel-strategy', json={'id': history['id']})
        assert cancelled.status_code == 200, cancelled.json
        with conn.cursor() as cursor:
            cursor.execute('SELECT price FROM products WHERE id=900001')
            assert cursor.fetchone()['price'] == 220
        print('PASS: API partner validation, saving, base price, candidate list, active list, retries and cancellation')
