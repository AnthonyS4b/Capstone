-- Run once for an incomplete import where the log ID already has a primary
-- key but is missing AUTO_INCREMENT. Back up ml_recommendation_logs first.
UPDATE ml_recommendation_logs
SET id = (SELECT next_id FROM (
    SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM ml_recommendation_logs
) AS sequence_start)
WHERE id = 0;

ALTER TABLE ml_recommendation_logs
    MODIFY id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT;
