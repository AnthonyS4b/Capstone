<?php
// controllers/CategoryController.php
require_once __DIR__ . '/../config/database.php';

class CategoryController {
    private $conn;
    
    public function __construct() {
        // Use PDO connection
        $this->conn = getDBConnection();
    }
    
    // Get all categories with product counts
    public function getAllCategories() {
        try {
            $sql = "SELECT c.*, COUNT(p.id) as product_count 
                    FROM categories c
                    LEFT JOIN products p ON c.id = p.category_id AND p.deleted_at IS NULL
                    WHERE c.deleted_at IS NULL
                    GROUP BY c.id
                    ORDER BY c.name ASC";
            
            $stmt = $this->conn->query($sql);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $categories];
            
        } catch (Exception $e) {
            error_log("Error in getAllCategories: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get single category
    public function getCategory($id) {
        try {
            $sql = "SELECT * FROM categories WHERE id = :id AND deleted_at IS NULL";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($category) {
                return ['success' => true, 'data' => $category];
            }
            return ['success' => false, 'message' => 'Category not found'];
            
        } catch (Exception $e) {
            error_log("Error in getCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Create category
    public function createCategory($data) {
        try {
            // Server-side duplicate name check (case-insensitive, active categories only)
            $dupSql  = "SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND deleted_at IS NULL LIMIT 1";
            $dupStmt = $this->conn->prepare($dupSql);
            $dupStmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
            $dupStmt->execute();
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                return ['success' => false, 'message' => 'A category named "' . $data['name'] . '" already exists.'];
            }

            $sql = "INSERT INTO categories (name, description, icon, color, status) 
                    VALUES (:name, :description, :icon, :color, :status)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
            $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
            $stmt->bindParam(':icon', $data['icon'], PDO::PARAM_STR);
            $stmt->bindParam(':color', $data['color'], PDO::PARAM_STR);
            $stmt->bindParam(':status', $data['status'], PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $data['id'] = $this->conn->lastInsertId();
                return ['success' => true, 'message' => 'Category created successfully', 'data' => $data];
            } else {
                return ['success' => false, 'message' => 'Failed to create category'];
            }
        } catch (Exception $e) {
            error_log("Error in createCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Update category
    public function updateCategory($id, $data) {
        try {
            // Verify the category exists and is not archived
            $existSql  = "SELECT id FROM categories WHERE id = :id AND deleted_at IS NULL LIMIT 1";
            $existStmt = $this->conn->prepare($existSql);
            $existStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $existStmt->execute();
            if (!$existStmt->fetch(PDO::FETCH_ASSOC)) {
                return ['success' => false, 'message' => 'Category not found or has been archived.'];
            }

            // Server-side duplicate name check — exclude the category being edited
            $dupSql  = "SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id AND deleted_at IS NULL LIMIT 1";
            $dupStmt = $this->conn->prepare($dupSql);
            $dupStmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
            $dupStmt->bindParam(':id',   $id,           PDO::PARAM_INT);
            $dupStmt->execute();
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                return ['success' => false, 'message' => 'A category named "' . $data['name'] . '" already exists.'];
            }

            $sql = "UPDATE categories SET 
                        name        = :name, 
                        description = :description, 
                        icon        = :icon, 
                        color       = :color, 
                        status      = :status 
                    WHERE id = :id AND deleted_at IS NULL";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':name',        $data['name'],        PDO::PARAM_STR);
            $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
            $stmt->bindParam(':icon',        $data['icon'],        PDO::PARAM_STR);
            $stmt->bindParam(':color',       $data['color'],       PDO::PARAM_STR);
            $stmt->bindParam(':status',      $data['status'],      PDO::PARAM_STR);
            $stmt->bindParam(':id',          $id,                  PDO::PARAM_INT);
            
            $stmt->execute();
            // rowCount() > 0 means a row was actually changed.
            // rowCount() === 0 can also mean the data was identical — that's still a success.
            return ['success' => true, 'message' => 'Category updated successfully'];

        } catch (Exception $e) {
            error_log("Error in updateCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Delete category (permanent delete)
    public function deleteCategory($id) {
        try {
            // Check if category has products
            $checkSql = "SELECT COUNT(*) as count FROM products WHERE category_id = :id AND deleted_at IS NULL";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row['count'] > 0) {
                return ['success' => false, 'message' => 'Cannot delete category with existing products'];
            }
            
            $sql = "DELETE FROM categories WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Category deleted successfully'];
            } else {
                return ['success' => false, 'message' => 'Category not found'];
            }
        } catch (Exception $e) {
            error_log("Error in deleteCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Archive category (soft delete)
    public function archiveCategory($id, $deleted_by = null) {
        try {
            // First, archive all products in this category
            $archiveProductsSql = "UPDATE products SET deleted_at = NOW(), deleted_by = :deleted_by WHERE category_id = :category_id AND deleted_at IS NULL";
            $archiveProductsStmt = $this->conn->prepare($archiveProductsSql);
            $archiveProductsStmt->bindParam(':deleted_by', $deleted_by, PDO::PARAM_INT);
            $archiveProductsStmt->bindParam(':category_id', $id, PDO::PARAM_INT);
            $archiveProductsStmt->execute();
            $productsArchived = $archiveProductsStmt->rowCount();
            
            // Then archive the category
            $sql = "UPDATE categories SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id AND deleted_at IS NULL";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':deleted_by', $deleted_by, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => "Category and $productsArchived products moved to archive"];
            } else {
                return ['success' => false, 'message' => 'Category not found or already archived'];
            }
        } catch (Exception $e) {
            error_log("Error in archiveCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get archived categories
    public function getArchivedCategories() {
        try {
            $sql = "SELECT c.*, COUNT(p.id) as product_count,
                    DATE_FORMAT(c.deleted_at, '%M %d, %Y %h:%i %p') as formatted_deleted_at
                    FROM categories c
                    LEFT JOIN products p ON c.id = p.category_id
                    WHERE c.deleted_at IS NOT NULL
                    GROUP BY c.id
                    ORDER BY c.deleted_at DESC";
            
            $stmt = $this->conn->query($sql);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $categories];
            
        } catch (Exception $e) {
            error_log("Error in getArchivedCategories: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Restore category from archive
    public function restoreCategory($id) {
        try {
            // Restore the category itself
            $sql = "UPDATE categories SET deleted_at = NULL, deleted_by = NULL WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);

            if (!($stmt->execute() && $stmt->rowCount() > 0)) {
                return ['success' => false, 'message' => 'Category not found or already active'];
            }

            // Restore only products that have NO past completed transactions
            $restoreProducts = "
                UPDATE products 
                SET deleted_at = NULL, deleted_by = NULL 
                WHERE category_id = :category_id 
                  AND deleted_at IS NOT NULL
                  AND id NOT IN (
                      SELECT DISTINCT p2.id 
                      FROM products p2
                      JOIN transaction_items ti ON ti.product_id = p2.id
                      JOIN transactions t ON t.id = ti.transaction_id
                      LEFT JOIN sales s ON s.transaction_id = t.id
                      WHERE p2.category_id = :category_id2
                        AND (s.status IS NULL OR s.status = 'completed')
                  )
            ";
            $prodStmt = $this->conn->prepare($restoreProducts);
            $prodStmt->bindParam(':category_id',  $id, PDO::PARAM_INT);
            $prodStmt->bindParam(':category_id2', $id, PDO::PARAM_INT);
            $prodStmt->execute();
            $productsRestored = $prodStmt->rowCount();

            // Count products left in archive (have transactions — stay archived)
            $leftStmt = $this->conn->prepare("
                SELECT COUNT(*) FROM products 
                WHERE category_id = :id AND deleted_at IS NOT NULL
            ");
            $leftStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $leftStmt->execute();
            $leftInArchive = (int)$leftStmt->fetchColumn();

            $msg = "Category restored with {$productsRestored} product(s) returned to inventory.";
            if ($leftInArchive > 0) {
                $msg .= " {$leftInArchive} product(s) with past transaction records remain in archive.";
            }

            return ['success' => true, 'message' => $msg];

        } catch (Exception $e) {
            error_log("Error in restoreCategory: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    // Check whether a category has products linked to past completed transactions.
    public function checkCategoryTransactions($id) {
        try {
            $sql = "SELECT
                        COUNT(DISTINCT ti.transaction_id) AS tx_count,
                        COUNT(DISTINCT p.id)              AS prod_count
                    FROM products p
                    JOIN transaction_items ti ON ti.product_id = p.id
                    JOIN transactions      t  ON t.id = ti.transaction_id
                    LEFT JOIN sales        s  ON s.transaction_id = t.id
                    WHERE p.category_id = :id
                      AND (s.status IS NULL OR s.status = 'completed')";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $txCount   = (int)($row['tx_count']  ?? 0);
            $prodCount = (int)($row['prod_count'] ?? 0);

            return [
                'success'           => true,
                'has_transactions'  => $txCount > 0,
                'transaction_count' => $txCount,
                'product_count'     => $prodCount
            ];

        } catch (Exception $e) {
            error_log("Error in checkCategoryTransactions: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    // Set a category active or inactive without archiving it.
    public function updateCategoryStatus($id, $status) {
        try {
            $allowed = ['active', 'inactive'];
            if (!in_array($status, $allowed)) {
                return ['success' => false, 'message' => 'Invalid status value.'];
            }

            $sql  = "UPDATE categories SET status = :status WHERE id = :id AND deleted_at IS NULL";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':id',     $id,     PDO::PARAM_INT);

            if ($stmt->execute() && $stmt->rowCount() > 0) {
                $label = $status === 'inactive' ? 'inactive' : 'active';
                return ['success' => true, 'message' => "Category set to {$label} successfully."];
            }

            return ['success' => false, 'message' => 'Category not found or status unchanged.'];

        } catch (Exception $e) {
            error_log("Error in updateCategoryStatus: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

}
?>