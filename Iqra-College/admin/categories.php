<?php
/**
 * Admin - Manage Categories with Update/Edit
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$error = '';
$success = '';

// Function to generate slug from name
function generateSlug($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $icon = sanitize($_POST['icon'] ?? '');
        
        if (empty($name)) {
            $error = 'Please enter category name';
        } else {
            try {
                // Check if name already exists (always check by name)
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    $error = 'Category name already exists';
                } else {
                    // Generate slug from name
                    $slug = generateSlug($name);
                    
                    // Try multiple INSERT attempts with fallback for missing columns
                    $inserted = false;
                    $insertAttempts = [
                        // Full insert with all columns including slug
                        [
                            'sql' => "INSERT INTO categories (name, slug, description, icon) VALUES (?, ?, ?, ?)",
                            'params' => [$name, $slug, $description, $icon]
                        ],
                        // Without slug
                        [
                            'sql' => "INSERT INTO categories (name, description, icon) VALUES (?, ?, ?)",
                            'params' => [$name, $description, $icon]
                        ],
                        // Without description and icon
                        [
                            'sql' => "INSERT INTO categories (name) VALUES (?)",
                            'params' => [$name]
                        ]
                    ];
                    
                    foreach ($insertAttempts as $attempt) {
                        try {
                            $stmt = $pdo->prepare($attempt['sql']);
                            $stmt->execute($attempt['params']);
                            $inserted = true;
                            $success = 'Category created successfully';
                            break;
                        } catch (PDOException $e) {
                            // Continue to next attempt
                            continue;
                        }
                    }
                    
                    if (!$inserted) {
                        throw new Exception('Failed to insert category with any column combination');
                    }
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'Category name already exists';
                } else {
                    $error = 'Failed to create category: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update') {
        $category_id = intval($_POST['category_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $icon = sanitize($_POST['icon'] ?? '');
        
        if (empty($name) || $category_id <= 0) {
            $error = 'Please fill in all required fields';
        } else {
            try {
                // Check if name already exists (excluding current category)
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
                $stmt->execute([$name, $category_id]);
                if ($stmt->fetch()) {
                    $error = 'Category name already exists';
                } else {
                    // Generate slug from name
                    $slug = generateSlug($name);
                    
                    // Try multiple UPDATE attempts with fallback for missing columns
                    $updated = false;
                    $updateAttempts = [
                        // Full update with all columns including slug
                        [
                            'sql' => "UPDATE categories SET name = ?, slug = ?, description = ?, icon = ? WHERE id = ?",
                            'params' => [$name, $slug, $description, $icon, $category_id]
                        ],
                        // Without slug
                        [
                            'sql' => "UPDATE categories SET name = ?, description = ?, icon = ? WHERE id = ?",
                            'params' => [$name, $description, $icon, $category_id]
                        ],
                        // Without description and icon
                        [
                            'sql' => "UPDATE categories SET name = ? WHERE id = ?",
                            'params' => [$name, $category_id]
                        ]
                    ];
                    
                    foreach ($updateAttempts as $attempt) {
                        try {
                            $stmt = $pdo->prepare($attempt['sql']);
                            $stmt->execute($attempt['params']);
                            $updated = true;
                            $success = 'Category updated successfully';
                            break;
                        } catch (PDOException $e) {
                            // Continue to next attempt
                            continue;
                        }
                    }
                    
                    if (!$updated) {
                        throw new Exception('Failed to update category with any column combination');
                    }
                }
            } catch (PDOException $e) {
                $error = 'Failed to update category: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Unassign courses from this category (set category_id to NULL)
                $stmt = $pdo->prepare("UPDATE courses SET category_id = NULL WHERE category_id = ?");
                $stmt->execute([$id]);
                $unassigned = $stmt->rowCount();

                // Delete the category
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Category deleted successfully';
                if ($unassigned > 0) {
                    $success .= '. ' . $unassigned . ' course(s) were unassigned from this category.';
                }
            } catch (PDOException $e) {
                $error = 'Failed to delete category';
            }
        }
    }
}

// Check for edit mode
$editCategoryId = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$editCategory = null;

if ($editCategoryId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$editCategoryId]);
    $editCategory = $stmt->fetch();
}

// Get all categories
try {
    $stmt = $pdo->query("SELECT c.*, COUNT(co.id) as course_count 
                        FROM categories c 
                        LEFT JOIN courses co ON c.id = co.category_id 
                        GROUP BY c.id 
                        ORDER BY c.created_at DESC");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    // If created_at column doesn't exist, order by id instead
    if (strpos($e->getMessage(), 'created_at') !== false) {
        $stmt = $pdo->query("SELECT c.*, COUNT(co.id) as course_count 
                            FROM categories c 
                            LEFT JOIN courses co ON c.id = co.category_id 
                            GROUP BY c.id 
                            ORDER BY c.id DESC");
        $categories = $stmt->fetchAll();
    } else {
        throw $e;
    }
}

$currentPage = 'categories.php';
$pageTitle = 'Categories';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode'])&&$_COOKIE['dark_mode']==='enabled'?'dark':''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class'};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__.'/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Course categories</p>
                        </div>
                    </div>
                    <?php include __DIR__.'/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
        <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-300 rounded-lg"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-300 rounded-lg"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">
                <?php echo $editCategory ? 'Edit Category' : 'Create New Category'; ?>
                <?php if ($editCategory): ?><a href="categories.php" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline ml-2">(Cancel)</a><?php endif; ?>
            </h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="<?php echo $editCategory ? 'update' : 'add'; ?>">
                <?php if ($editCategory): ?><input type="hidden" name="category_id" value="<?php echo $editCategory['id']; ?>"><?php endif; ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Category Name *</label>
                        <input type="text" name="name" required placeholder="e.g., English Grammar" value="<?php echo $editCategory ? htmlspecialchars($editCategory['name']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Slug auto-generated from name</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Icon (Emoji/Text)</label>
                        <input type="text" name="icon" placeholder="e.g., 📚" maxlength="50" value="<?php echo $editCategory ? htmlspecialchars($editCategory['icon'] ?? '') : ''; ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" placeholder="Optional" rows="3" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"><?php echo $editCategory ? htmlspecialchars($editCategory['description'] ?? '') : ''; ?></textarea>
                </div>
                <div class="flex gap-4">
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-xl font-semibold hover:bg-indigo-700"><?php echo $editCategory ? 'Update' : 'Create'; ?> Category</button>
                    <?php if ($editCategory): ?><a href="categories.php" class="bg-gray-500 text-white px-6 py-2 rounded-xl font-semibold hover:bg-gray-600">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-folder mr-2 text-amber-500"></i>All Categories</h2>
            
            <?php if (empty($categories)): ?>
                <div class="text-center py-12 text-gray-500 dark:text-gray-400"><i class="fas fa-folder-open text-5xl mb-3 opacity-50"></i><p class="text-lg">No categories yet. Create one above.</p></div>
            <?php else: ?>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($categories as $c): ?>
                        <div class="bg-white dark:bg-gray-700/50 border-2 border-gray-200 dark:border-gray-600 rounded-2xl p-6 hover:border-amber-400 dark:hover:border-amber-500 transition-colors">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <span class="text-3xl"><?php echo !empty($c['icon']) ? htmlspecialchars($c['icon']) : '📁'; ?></span>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($c['name']); ?></h3>
                                        <?php if (!empty($c['slug'])): ?><p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($c['slug']); ?></p><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($c['description'])): ?><p class="text-gray-600 dark:text-gray-400 text-sm mb-4 line-clamp-2"><?php echo htmlspecialchars($c['description']); ?></p><?php endif; ?>
                            <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-600 gap-2">
                                <span class="text-sm text-gray-600 dark:text-gray-400"><span class="font-semibold"><?php echo $c['course_count']; ?></span> <?php echo $c['course_count']==1?'course':'courses'; ?></span>
                                <span class="inline-flex gap-2">
                                    <a href="categories.php?edit=<?php echo $c['id']; ?>" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Edit</a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this category? Courses will be unassigned.');">
                                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-red-700">Delete</button>
                                    </form>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        </main>
    </div>
</body>
</html>
