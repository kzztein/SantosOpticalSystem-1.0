<?php
    include_once 'setup.php';
    require_once 'connect.php';
    include 'ActivityTracker.php';

    function buildQueryString($page = null, $currentParams = []) {
        $params = [];
        
        // Preserve all existing query parameters
        if (isset($currentParams['sort'])) $params['sort'] = $currentParams['sort'];
        if (isset($currentParams['search'])) $params['search'] = $currentParams['search'];
        if (isset($currentParams['shape'])) $params['shape'] = $currentParams['shape'];
        if (isset($currentParams['category'])) $params['category'] = $currentParams['category'];
        if (isset($currentParams['branch'])) $params['branch'] = $currentParams['branch'];
        
        if ($page !== null) {
            $params['page'] = $page;
        }
        
        return '?' . http_build_query($params);
    }

    function getFaceShapeName($shapeID) {
        $conn = connect();
        $sql = "SELECT Description FROM shapeMaster WHERE ShapeID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $shapeID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['Description'];
        }
        return 'Not specified';
    }

 function pagination() {
    $conn = connect();

    $perPage = 12; 
    $page = (isset($_GET['page'])) ? (int)$_GET['page'] : 1;
    $start = ($page - 1) * $perPage;
    
    // Get parameters from URL
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'name_asc';
    $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    $shape = isset($_GET['shape']) ? (int)$_GET['shape'] : 0;
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $branch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
    
    // Build different queries for All Branches vs Specific Branch
    if ($branch > 0) {
        // Specific Branch View - show only products available at this branch
        $sql = "SELECT DISTINCT p.*, pb.Stocks, b.BranchName
                FROM `productMstr` p
                JOIN ProductBranchMaster pb ON p.ProductID = pb.ProductID
                JOIN BranchMaster b ON pb.BranchCode = b.BranchCode
                LEFT JOIN archives a ON (p.ProductID = a.TargetID AND a.TargetType = 'product')
                WHERE (pb.Avail_FL = 'Available' OR pb.Avail_FL IS NULL)
                AND a.ArchiveID IS NULL
                AND pb.BranchCode = $branch";
    } else {
        // All Branches View - show each product once with all available branches
        $sql = "SELECT p.*, 
                (SELECT GROUP_CONCAT(DISTINCT b.BranchName SEPARATOR ', ') 
                 FROM ProductBranchMaster pb 
                 JOIN BranchMaster b ON pb.BranchCode = b.BranchCode 
                 WHERE pb.ProductID = p.ProductID AND (pb.Avail_FL = 'Available' OR pb.Avail_FL IS NULL)) as AvailableBranches,
                (SELECT MIN(pb.Stocks) FROM ProductBranchMaster pb WHERE pb.ProductID = p.ProductID) as MinStocks
                FROM `productMstr` p
                LEFT JOIN archives a ON (p.ProductID = a.TargetID AND a.TargetType = 'product')
                WHERE (p.Avail_FL = 'Available' OR p.Avail_FL IS NULL)
                AND a.ArchiveID IS NULL";
    }
    
    $whereConditions = [];
    
    if (!empty($search)) {
        $whereConditions[] = "p.Model LIKE '%" . $search . "%'";
    }
    
    if ($shape > 0) {
        $whereConditions[] = "p.ShapeID = $shape";
    }
    
    if (!empty($category)) {
        $category = mysqli_real_escape_string($conn, $category);
        $whereConditions[] = "p.CategoryType = '$category'";
    }
    
    if (!empty($whereConditions)) {
        $sql .= " AND " . implode(' AND ', $whereConditions);
    }
    
    switch($sort) {
        case 'price_asc':
            $sql .= " ORDER BY CAST(REPLACE(REPLACE(p.Price, '₱', ''), ',', '') AS DECIMAL(10,2)) ASC";
            break;
        case 'price_desc':
            $sql .= " ORDER BY CAST(REPLACE(REPLACE(p.Price, '₱', ''), ',', '') AS DECIMAL(10,2)) DESC";
            break;
        case 'name_asc':
            $sql .= " ORDER BY p.Model ASC";
            break;
        case 'name_desc':
            $sql .= " ORDER BY p.Model DESC";
            break;
        default:
            $sql .= " ORDER BY p.Model ASC";
    }
    
    // Get total count - build separate count queries
    if ($branch > 0) {
        $countSql = "SELECT COUNT(DISTINCT p.ProductID) as total
                    FROM `productMstr` p
                    JOIN ProductBranchMaster pb ON p.ProductID = pb.ProductID
                    JOIN BranchMaster b ON pb.BranchCode = b.BranchCode
                    LEFT JOIN archives a ON (p.ProductID = a.TargetID AND a.TargetType = 'product')
                    WHERE (pb.Avail_FL = 'Available' OR pb.Avail_FL IS NULL)
                    AND a.ArchiveID IS NULL
                    AND pb.BranchCode = $branch";
    } else {
        $countSql = "SELECT COUNT(DISTINCT p.ProductID) as total
                    FROM `productMstr` p
                    LEFT JOIN archives a ON (p.ProductID = a.TargetID AND a.TargetType = 'product')
                    WHERE (p.Avail_FL = 'Available' OR p.Avail_FL IS NULL)
                    AND a.ArchiveID IS NULL";
    }

    // Add the same WHERE conditions to count query
    if (!empty($whereConditions)) {
        $countSql .= " AND " . implode(' AND ', $whereConditions);
    }
    
    $countResult = mysqli_query($conn, $countSql);
    $totalData = mysqli_fetch_assoc($countResult);
    $total = $totalData['total'];
    $totalPages = ceil($total / $perPage);

    // Add pagination limits
    $sql .= " LIMIT $start, $perPage";
    $result = mysqli_query($conn, $sql);
    
    echo "<div class='row row-cols-2 row-cols-md-3 row-cols-lg-4 g-4' id='productGrid'>";
    
    if ($total > 0) {
        while($row = mysqli_fetch_assoc($result)) {
            $searchableText = strtolower($row['Model']);
            $faceShape = isset($row['ShapeID']) ? getFaceShapeName($row['ShapeID']) : 'Not specified';
            
            echo "<div class='col d-flex product-card' data-search='".htmlspecialchars($searchableText, ENT_QUOTES)."'>";
                echo "<div class='card w-100' style='max-width: 380px;'>";
                    echo '<img src="' . $row['ProductImage']. '" class="card-img-top img-fluid" style="height: 280px;" alt="'. $row['Model'] .'">';
                    echo "<div class='card-body d-flex flex-column'>";
                        echo "<h5 class='card-title' style='min-height: 1.5rem;'>".$row['Model']."</h5>";
                        echo "<hr>";
                        echo "<div class='card-text mb-2'>".$row['CategoryType']."</div>";
                        echo "<div class='card-text mb-2'>".$row['Material']."</div>";
                        $price = $row['Price'];
                        $numeric_price = preg_replace('/[^0-9.]/', '', $price);
                        $formatted_price = is_numeric($numeric_price) ? '₱' . number_format((float)$numeric_price, 2) : '₱0.00';
                        echo "<div class='card-text mb-2'>".$formatted_price."</div>";
                        
                        if ($branch > 0) {
                            // Specific branch view
                            $stock = $row['Stocks'];
                            $branchName = $row['BranchName'];
                            $availability = ($stock > 0) ? "Available at $branchName" : "Out of stock at $branchName";
                            
                            if ($stock > 0) {
                                echo "<div class='card-text mb-2 text-success'>$availability</div>";
                                echo "</div>";
                                echo "<div class='card-footer bg-transparent border-top-0 mt-auto pt-0'>";
                                    echo "<button type='button' class='btn btn-primary w-100 py-2 view-details' data-bs-toggle='modal' data-bs-target='#productModal' 
                                          data-product-id='".$row['ProductID']."'
                                          data-product-name='".htmlspecialchars($row['Model'], ENT_QUOTES)."'
                                          data-product-image='".htmlspecialchars($row['ProductImage'], ENT_QUOTES)."'
                                          data-product-category='".htmlspecialchars($row['CategoryType'], ENT_QUOTES)."'
                                          data-product-material='".htmlspecialchars($row['Material'], ENT_QUOTES)."'
                                          data-product-price='".htmlspecialchars($formatted_price, ENT_QUOTES)."'
                                          data-product-availability='".htmlspecialchars($availability, ENT_QUOTES)."'
                                          data-product-stock='".htmlspecialchars($stock, ENT_QUOTES)."'
                                          data-product-faceshape='".htmlspecialchars($faceShape, ENT_QUOTES)."'>
                                          More details
                                      </button>";
                                echo "</div>";
                            } else {
                                echo "<div class='card-text mb-2 text-danger'>$availability</div>";
                                echo "</div>";
                                echo "<div class='card-footer bg-transparent border-top-0 mt-auto pt-0'>";
                                    echo "<a href='#' class='btn btn-secondary w-100 py-2 disabled'>Not Available</a>";
                                echo "</div>";
                            }
                        } else {
                            // All branches view
                            $availableBranches = $row['AvailableBranches'];
                            $minStock = $row['MinStocks'];
                            
                            if (!empty($availableBranches)) {
                                echo "<div class='card-text mb-2 text-success'>Available at: $availableBranches</div>";
                                echo "</div>";
                                echo "<div class='card-footer bg-transparent border-top-0 mt-auto pt-0'>";
                                    echo "<button type='button' class='btn btn-primary w-100 py-2 view-details' data-bs-toggle='modal' data-bs-target='#productModal' 
                                          data-product-id='".$row['ProductID']."'
                                          data-product-name='".htmlspecialchars($row['Model'], ENT_QUOTES)."'
                                          data-product-image='".htmlspecialchars($row['ProductImage'], ENT_QUOTES)."'
                                          data-product-category='".htmlspecialchars($row['CategoryType'], ENT_QUOTES)."'
                                          data-product-material='".htmlspecialchars($row['Material'], ENT_QUOTES)."'
                                          data-product-price='".htmlspecialchars($formatted_price, ENT_QUOTES)."'
                                          data-product-availability='".htmlspecialchars($availableBranches, ENT_QUOTES)."'
                                          data-product-stock='".htmlspecialchars($minStock, ENT_QUOTES)."'
                                          data-product-faceshape='".htmlspecialchars($faceShape, ENT_QUOTES)."'>
                                          More details
                                      </button>";
                                echo "</div>";
                            } else {
                                echo "<div class='card-text mb-2 text-danger'>Not available at any branch</div>";
                                echo "</div>";
                                echo "<div class='card-footer bg-transparent border-top-0 mt-auto pt-0'>";
                                    echo "<a href='#' class='btn btn-secondary w-100 py-2 disabled'>Not Available</a>";
                                echo "</div>";
                            }
                        }
                    echo "</div>";
                echo "</div>";
            echo "</div>";
        }
    } else {
        echo "<div class='col-12 py-5 no-results' style='display: flex; justify-content: center; align-items: center; min-height: 300px;'>";
        if ($shape > 0) {
            $shapeName = getFaceShapeName($shape);
            echo "<h4 class='text-center'>No products found for frame shape: $shapeName</h4>";
        } else if ($branch > 0) {
            $conn = connect();
            $branchQuery = "SELECT BranchName FROM BranchMaster WHERE BranchCode = $branch";
            $branchResult = mysqli_query($conn, $branchQuery);
            $branchName = mysqli_fetch_assoc($branchResult)['BranchName'];
            $conn->close();
            echo "<h4 class='text-center'>No products found at branch: $branchName</h4>";
        } else {
            echo "<h4 class='text-center'>No products found matching your search.</h4>";
        }
        echo "</div>";
    }
    
    echo "</div>"; 

    // Pagination links
    if ($totalPages > 1) {
        echo "<div class='col-12 mt-5'>";
            echo "<div class='d-flex justify-content-center'>";
                echo "<ul class='pagination'>";
                if ($page > 1) {
                    echo "<li class='page-item'><a class='page-link' href='" . buildQueryString($page - 1, $_GET) . "'>Previous</a></li>";
                } else {
                    echo "<li class='page-item disabled'><a class='page-link'>Previous</a></li>";
                }

                $maxPagesToShow = 5;
                $startPage = max(1, $page - floor($maxPagesToShow / 2));
                $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);
                
                if ($endPage - $startPage < $maxPagesToShow - 1) {
                    $startPage = max(1, $endPage - $maxPagesToShow + 1);
                }
                
                if ($startPage > 1) {
                    echo "<li class='page-item'><a class='page-link' href='" . buildQueryString(1, $_GET) . "'>1</a></li>";
                    if ($startPage > 2) {
                        echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++) {                       
                    if ($i == $page) {
                        echo "<li class='page-item active' aria-current='page'><a class='page-link disabled'>$i</a></li>"; 
                    } else {
                        echo "<li class='page-item'><a class='page-link' href='" . buildQueryString($i, $_GET) . "'>$i</a></li>";
                    }
                }
                
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                    }
                    echo "<li class='page-item'><a class='page-link' href='" . buildQueryString($totalPages, $_GET) . "'>$totalPages</a></li>";
                }

                if ($page < $totalPages) {
                    echo "<li class='page-item'><a class='page-link' href='" . buildQueryString($page + 1, $_GET) . "'>Next</a></li>";
                } else {
                    echo "<li class='page-item disabled'><a class='page-link'>Next</a></li>";
                }
                echo "</ul>";
            echo "</div>";
        echo "</div>"; 
    }
}
?>


<!DOCTYPE html>
<html>
    <head>
    <title>Products</title>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <link rel="stylesheet" href="customCodes/s1.css">
        <link rel="stylesheet" href="customCodes/custom.css">
        <link rel="shortcut icon" type="image/x-icon" href="Images/logo.png"/>
        <link rel="stylesheet" href="customCodes/s2.css">
        <style>
            @media (min-width: 768px) {
                .container {
                    max-width: 95%;
                }
            }
            @media (min-width: 1200px) {
                .container {
                    max-width: 1400px;
                }
            }
            .card {
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                transition: transform 0.3s ease;
            }
            .card:hover {
                transform: translateY(-5px);
            }
            .filter-container {
                display: flex;
                justify-content: flex-end;
                gap: 20px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }
            .filter-dropdown {
                max-width: 250px;
                min-width: 200px;
            }
            .search-container {
                margin-bottom: 30px;
            }
            .search-box {
                max-width: 500px;
                margin: 0 auto;
            }
            .product-card.hidden {
                display: none;
            }
            #liveSearchResults {
                position: absolute;
                width: 100%;
                max-width: 500px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 1000;
                background: white;
                border: 1px solid #ddd;
                border-radius: 0 0 5px 5px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                max-height: 300px;
                overflow-y: auto;
                display: none;
            }
            .live-search-item {
                padding: 10px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
            }
            .live-search-item:hover {
                background-color: #f8f9fa;
            }
            .live-search-item.highlight {
                background-color: #e9ecef;
            }
            
            .product-image-container {
                height: 350px;
                border: 1px solid #eee;
                border-radius: 0.5rem;
                background-color: #f8f9fa;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }
            
            .modal-lg {
                max-width: 900px;
            }
            
            .detail-label {
                font-weight: 600;
                color: #6c757d;
            }
            
            .badge.available {
                background-color: #d1e7dd;
                color: #0f5132;
            }
            
            .badge.not-available {
                background-color: #f8d7da;
                color: #842029;
            }
            
            .badge.low-stock {
                background-color: #fff3cd;
                color: #664d03;
            }
            
            .list-group-item {
                background-color: transparent;
                border-color: rgba(0,0,0,0.05);
            }
        </style>
    </head>

    <header>
        <?php
            include "Navigation.php";
        ?>
    </header>

    <body>
        <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title fw-bold" id="productModalLabel">Product Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="product-image-container">
                                    <img id="modalProductImage" src="" class="img-fluid mh-100" alt="Product Image" style="max-height: 300px; width: auto; object-fit: contain;">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="d-flex flex-column h-100">
                                    <div class="mb-3 border-bottom pb-3">
                                        <h3 id="modalProductName" class="fw-bold mb-2"></h3>
                                        <div>
                                            <span id="modalProductStock" class="badge rounded-pill fs-6"></span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex-grow-1">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                                                <span class="fw-semibold text-muted">Category:</span>
                                                <span id="modalProductCategory" class="text-end"></span>
                                            </li>
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                                                <span class="fw-semibold text-muted">Material:</span>
                                                <span id="modalProductMaterial" class="text-end"></span>
                                            </li>
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                                                <span class="fw-semibold text-muted">Price:</span>
                                                <span id="modalProductPrice" class="text-end fw-bold text-primary"></span>
                                            </li>
                                            <li class="list-group-item px-0 py-2 d-flex justify-content-between">
                                                <span class="fw-semibold text-muted">Frame Shape:</span>
                                                <span id="modalProductFaceShape" class="text-end"></span>
                                            </li>
                                        </ul>
                                    </div>
                                    
                                    <div class="mt-auto pt-3 border-top">
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                <i class="fas fa-times me-2"></i>Close
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

       <div class="container" style="margin-top: 2rem;">
            <div class="container mb-4">
                <h1 style='text-align: center;'>Products</h1>
                <div class="search-container">
                    <form method="get" action="" class="search-box position-relative" id="searchForm">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="searchInput" name="search" placeholder="Search products..." 
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                   autocomplete="off">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                            <?php if(isset($_GET['search']) && !empty($_GET['search'])): ?>
                                <a href="?" class="btn btn-outline-secondary">Clear</a>
                            <?php endif; ?>
                            <?php if(isset($_GET['sort'])): ?>
                                <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
                            <?php endif; ?>
                            <?php if(isset($_GET['shape'])): ?>
                                <input type="hidden" name="shape" value="<?php echo $_GET['shape']; ?>">
                            <?php endif; ?>
                            <?php if(isset($_GET['category'])): ?>
                                <input type="hidden" name="category" value="<?php echo $_GET['category']; ?>">
                            <?php endif; ?>
                            <?php if(isset($_GET['branch'])): ?>
                                <input type="hidden" name="branch" value="<?php echo $_GET['branch']; ?>">
                            <?php endif; ?>
                        </div>
                        <div id="liveSearchResults"></div>
                    </form>
                </div>
                
                <div class="filter-container">
                    <!-- Branch Filter -->
                    <form method="get" action="" class="filter-dropdown">
                        <input type="hidden" name="page" value="1"> <!-- Reset to page 1 when changing filters -->
                        <?php if(isset($_GET['search'])): ?>
                            <input type="hidden" name="search" value="<?php echo $_GET['search']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['sort'])): ?>
                            <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['shape'])): ?>
                            <input type="hidden" name="shape" value="<?php echo $_GET['shape']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['category'])): ?>
                            <input type="hidden" name="category" value="<?php echo $_GET['category']; ?>">
                        <?php endif; ?>
                        <div class="input-group">
                            <label class="input-group-text" for="branchSelect">Branch:</label>
                            <select class="form-select" id="branchSelect" name="branch" onchange="this.form.submit()">
                                <option value="">All Branches</option>
                                <?php
                                    $conn = connect();
                                    $branchQuery = "SELECT BranchCode, BranchName FROM BranchMaster";
                                    $branchResult = mysqli_query($conn, $branchQuery);
                                    while ($branch = mysqli_fetch_assoc($branchResult)) {
                                        $selected = (isset($_GET['branch']) && $_GET['branch'] == $branch['BranchCode']) ? 'selected' : '';
                                        echo "<option value='{$branch['BranchCode']}' $selected>{$branch['BranchName']}</option>";
                                    }
                                    $conn->close();
                                ?>
                            </select>
                        </div>
                    </form>
                    
                    <!-- Frame Shape Filter -->
                    <form method="get" action="" class="filter-dropdown">
                        <input type="hidden" name="page" value="1"> <!-- Always reset to page 1 when applying a new filter -->
                        <?php if(isset($_GET['search'])): ?>
                            <input type="hidden" name="search" value="<?php echo $_GET['search']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['sort'])): ?>
                            <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['category'])): ?>
                            <input type="hidden" name="category" value="<?php echo $_GET['category']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['branch'])): ?>
                            <input type="hidden" name="branch" value="<?php echo $_GET['branch']; ?>">
                        <?php endif; ?>
                        <div class="input-group">
                            <label class="input-group-text" for="shapeSelect">Frame Shape:</label>
                            <select class="form-select" id="shapeSelect" name="shape" onchange="this.form.submit()">
                                <option value="">All Shapes</option>
                                <option value="1" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '1') ? 'selected' : ''; ?>>Oblong</option>
                                <option value="2" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '2') ? 'selected' : ''; ?>>V-Triangle</option>
                                <option value="3" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '3') ? 'selected' : ''; ?>>Diamond</option>
                                <option value="4" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '4') ? 'selected' : ''; ?>>Round</option>
                                <option value="5" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '5') ? 'selected' : ''; ?>>Square</option>
                                <option value="6" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '6') ? 'selected' : ''; ?>>A-Triangle</option>
                                <option value="7" <?php echo (isset($_GET['shape']) && $_GET['shape'] == '7') ? 'selected' : ''; ?>>Rectangle</option>
                            </select>
                        </div>
                    </form>
                    
                    <!-- Category Filter -->
                    <form method="get" action="" class="filter-dropdown">
                        <input type="hidden" name="page" value="1"> <!-- Always reset to page 1 when applying a new filter -->
                        <?php if(isset($_GET['search'])): ?>
                            <input type="hidden" name="search" value="<?php echo $_GET['search']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['sort'])): ?>
                            <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['shape'])): ?>
                            <input type="hidden" name="shape" value="<?php echo $_GET['shape']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['branch'])): ?>
                            <input type="hidden" name="branch" value="<?php echo $_GET['branch']; ?>">
                        <?php endif; ?>
                        <div class="input-group">
                            <label class="input-group-text" for="categorySelect">Category:</label>
                            <select class="form-select" id="categorySelect" name="category" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <option value="Bifocal Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Bifocal Lens') ? 'selected' : ''; ?>>Bifocal Lens</option>
                                <option value="Concave Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Concave Lens') ? 'selected' : ''; ?>>Concave Lens</option>
                                <option value="Contact Lenses" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Contact Lenses') ? 'selected' : ''; ?>>Contact Lenses</option>
                                <option value="Convex Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Convex Lens') ? 'selected' : ''; ?>>Convex Lens</option>
                                <option value="Frame" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Frame') ? 'selected' : ''; ?>>Frame</option>
                                <option value="Photochromic Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Photochromic Lens') ? 'selected' : ''; ?>>Photochromic Lens</option>
                                <option value="Polarized Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Polarized Lens') ? 'selected' : ''; ?>>Polarized Lens</option>
                                <option value="Progressive Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Progressive Lens') ? 'selected' : ''; ?>>Progressive Lens</option>
                                <option value="Sunglasses" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Sunglasses') ? 'selected' : ''; ?>>Sunglasses</option>
                                <option value="Trifocal Lens" <?php echo (isset($_GET['category']) && $_GET['category'] == 'Trifocal Lens') ? 'selected' : ''; ?>>Trifocal Lens</option>
                            </select>
                        </div>
                    </form>
                    
                    <!-- Sort Filter -->
                    <form method="get" action="" class="filter-dropdown">
                        <input type="hidden" name="page" value="1"> <!-- Always reset to page 1 when applying a new filter -->
                        <?php if(isset($_GET['search'])): ?>
                            <input type="hidden" name="search" value="<?php echo $_GET['search']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['shape'])): ?>
                            <input type="hidden" name="shape" value="<?php echo $_GET['shape']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['category'])): ?>
                            <input type="hidden" name="category" value="<?php echo $_GET['category']; ?>">
                        <?php endif; ?>
                        <?php if(isset($_GET['branch'])): ?>
                            <input type="hidden" name="branch" value="<?php echo $_GET['branch']; ?>">
                        <?php endif; ?>
                        <div class="input-group">
                            <label class="input-group-text" for="sortSelect">Sort by:</label>
                            <select class="form-select" id="sortSelect" name="sort" onchange="this.form.submit()">
                                <option value="name_asc" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'name_asc') ? 'selected' : ''; ?>>Name (A-Z)</option>
                                <option value="name_desc" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'name_desc') ? 'selected' : ''; ?>>Name (Z-A)</option>
                                <option value="price_asc" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'price_asc') ? 'selected' : ''; ?>>Price (Low to High)</option>
                                <option value="price_desc" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'price_desc') ? 'selected' : ''; ?>>Price (High to Low)</option>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="grid" style="margin-bottom: 3.5rem;">
                <?php
                    pagination();
                ?>
            </div>   
                <footer class="py-5 border-top mt-5 pt-4" style="background-color: #ffffff; margin-top: 50px; border-color: #ffffff;">
        <div class="container">
            <div class="row text-center text-md-start">
                <div class="col-md-3 mb-3 mb-md-0 text-center">
                    <img src="Images/logo.png" alt="Logo" width="200">
                </div>

                <div class="col-md-3 mb-3 mb-md-0">
                    <h6 class="fw-bold">PRODUCTS</h6>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-dark text-decoration-none">Frames</a></li>
                        <li><a href="#" class="text-dark text-decoration-none">Sunglasses</a></li>
                    </ul>
                </div>

                <div class="col-md-3 mb-3 mb-md-0">
                    <h6 class="fw-bold">About</h6>
                    <ul class="list-unstyled">
                        <li><a href="aboutus.php" class="text-dark text-decoration-none">About Us</a></li>
                        <li><a href="ourservices.php" class="text-dark text-decoration-none">Services</a></li>
                    </ul>
                </div>

                <div class="col-md-3">
                    <h6 class="fw-bold">CONTACT US!</h6>
                    <p class="mb-1">Address: #6 Rizal Avenue Extension, Brgy. San Agustin, Malabon City</p>
                    <p class="mb-1">Phone: 027-508-4792</p>
                    <p class="mb-1">Cell: 0932-844-7068</p>
                    <p>Email: <a href="mailto:Santosoptical@gmail.com" class="text-dark">Santosoptical@gmail.com</a></p>
                </div>
            </div>
            <div class="container-fluid text-center py-3" style="background-color: white">
                <p class="m-0">COPYRIGHT &copy; SANTOS OPTICAL co., ltd. ALL RIGHTS RESERVED.</p>
            </div>
        </div>
    </footer>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('searchInput');
                const liveSearchResults = document.getElementById('liveSearchResults');
                const searchForm = document.getElementById('searchForm');
                
                const productModal = document.getElementById('productModal');
                if (productModal) {
                    productModal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;
                        const productId = button.getAttribute('data-product-id');
                        const productName = button.getAttribute('data-product-name');
                        const productImage = button.getAttribute('data-product-image');
                        const productCategory = button.getAttribute('data-product-category');
                        const productMaterial = button.getAttribute('data-product-material');
                        const productPrice = button.getAttribute('data-product-price');
                        const productStock = parseInt(button.getAttribute('data-product-stock'));
                        const productFaceShape = button.getAttribute('data-product-faceshape');
                        
                        document.getElementById('modalProductName').textContent = productName;
                        document.getElementById('modalProductImage').src = productImage;
                        document.getElementById('modalProductImage').alt = productName;
                        document.getElementById('modalProductCategory').textContent = productCategory;
                        document.getElementById('modalProductMaterial').textContent = productMaterial;
                        document.getElementById('modalProductPrice').textContent = productPrice;
                        document.getElementById('modalProductFaceShape').textContent = productFaceShape;
                        
                        const stockBadge = document.getElementById('modalProductStock');
                        if (productStock > 0) {
                            stockBadge.textContent = productStock + ' in stock';
                            stockBadge.className = 'badge rounded-pill fs-6 ' + 
                                (productStock < 5 ? 'low-stock' : 'available');
                        } else {
                            stockBadge.textContent = 'Out of stock';
                            stockBadge.className = 'badge rounded-pill fs-6 not-available';
                        }
                    });
                }
                
                function performLiveSearch() {
                    const searchTerm = searchInput.value.trim();
                    
                    if (searchTerm.length < 1) {
                        liveSearchResults.style.display = 'none';
                        return;
                    }
                    
                    fetch(`search_products.php?term=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.json())
                        .then(results => {
                            liveSearchResults.innerHTML = '';
                            
                            if (results.length > 0) {
                                results.slice(0, 5).forEach(result => {
                                    const resultItem = document.createElement('div');
                                    resultItem.className = 'live-search-item';
                                    resultItem.textContent = result;
                                    
                                    resultItem.addEventListener('click', function() {
                                        searchInput.value = result;
                                        liveSearchResults.style.display = 'none';
                                        searchForm.submit();
                                    });
                                    
                                    liveSearchResults.appendChild(resultItem);
                                });
                                
                                if (results.length > 5) {
                                    const moreItem = document.createElement('div');
                                    moreItem.className = 'live-search-item text-center text-muted small';
                                    moreItem.textContent = `+${results.length - 5} more items...`;
                                    liveSearchResults.appendChild(moreItem);
                                }
                                
                                liveSearchResults.style.display = 'block';
                            } else {
                                liveSearchResults.innerHTML = '<div class="live-search-item text-muted">No matches found</div>';
                                liveSearchResults.style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            liveSearchResults.style.display = 'none';
                        });
                }
                
                searchInput.addEventListener('input', function() {
                    performLiveSearch();
                });
                
                searchInput.addEventListener('focus', function() {
                    if (searchInput.value.trim().length >= 1) {
                        performLiveSearch();
                    }
                });
                
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !liveSearchResults.contains(e.target)) {
                        liveSearchResults.style.display = 'none';
                    }
                });
                
                searchInput.addEventListener('keydown', function(e) {
                    const items = liveSearchResults.querySelectorAll('.live-search-item');
                    let currentHighlight = liveSearchResults.querySelector('.live-search-item.highlight');
                    
                    if (e.key === 'Escape') {
                        liveSearchResults.style.display = 'none';
                        return;
                    }
                    
                    if (items.length === 0) return;
                    
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (!currentHighlight) {
                            items[0].classList.add('highlight');
                        } else {
                            currentHighlight.classList.remove('highlight');
                            const next = currentHighlight.nextElementSibling || items[0];
                            next.classList.add('highlight');
                            next.scrollIntoView({ block: 'nearest' });
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (!currentHighlight) {
                            items[items.length - 1].classList.add('highlight');
                        } else {
                            currentHighlight.classList.remove('highlight');
                            const prev = currentHighlight.previousElementSibling || items[items.length - 1];
                            prev.classList.add('highlight');
                            prev.scrollIntoView({ block: 'nearest' });
                        }
                    } else if (e.key === 'Enter' && currentHighlight) {
                        e.preventDefault();
                        searchInput.value = currentHighlight.textContent;
                        liveSearchResults.style.display = 'none';
                        searchForm.submit();
                    }
                });
                
                // Submit the form when pressing Enter in the search input
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !liveSearchResults.querySelector('.live-search-item.highlight')) {
                        e.preventDefault();
                        searchForm.submit();
                    }
                });
            });
        </script>
    </body>
</html>
