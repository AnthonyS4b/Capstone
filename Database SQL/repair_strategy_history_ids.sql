-- Repair an incomplete import where strategy_history has no primary key or
-- AUTO_INCREMENT. Run once after backing up the table. Existing nonzero IDs
-- must be unique, and there must be at most one row with ID 0.
-- The complete espenida_pos.sql dump already defines these constraints.

UPDATE strategy_history
SET id = (SELECT next_id FROM (
    SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM strategy_history
) AS sequence_start)
WHERE id = 0;

ALTER TABLE strategy_history
    ADD PRIMARY KEY (id),
    MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT;
