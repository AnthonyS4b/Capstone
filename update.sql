UPDATE espenida_pos.strategy_templates SET conditions = '[\"packaged\", \"critical_expiry\", \"slow_moving\"]', risk_levels = '[\"CRITICAL\", \"WARNING\"]' WHERE id = 'buy_one_take_one';
