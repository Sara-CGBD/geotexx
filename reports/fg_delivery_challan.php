<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
// Normalize AGM Operations variations
if ($user_role === 'agm operations' || $user_role === 'agm_ops' || $user_role === 'agm_operations') {
    $user_role = 'agm ops';
}
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user', 'delivery_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$delivery_id = $_GET['id'] ?? '';

if (!$delivery_id) {
    die("No delivery ID provided");
}

// First, fetch the delivery to get the challan_no
$firstQuery = "SELECT challan_no, delivery_id FROM fg_deliveries WHERE id = ?";
$firstStmt = $conn->prepare($firstQuery);
$firstStmt->bind_param('i', $delivery_id);
$firstStmt->execute();
$firstResult = $firstStmt->get_result();
$firstDelivery = $firstResult->fetch_assoc();
$firstStmt->close();

if (!$firstDelivery) {
    die("Delivery not found");
}

// Get challan_no from the first delivery
$challan_no = $firstDelivery['challan_no'] ?? $firstDelivery['delivery_id'] ?? 'CH-' . str_pad($delivery_id, 5, '0', STR_PAD_LEFT);

// If challan_no is empty, use delivery_id as fallback
if (empty($challan_no)) {
    $challan_no = $firstDelivery['delivery_id'] ?? 'CH-' . str_pad($delivery_id, 5, '0', STR_PAD_LEFT);
}

// Fetch ALL delivery details with the same challan_no
// For rolls, get roll_size from roll_entry; for bags, get bag_size from fg_entry
$query = "SELECT 
    d.*,
    fe.bag_size as fg_bag_size,
    fe.packaging_type as fg_packaging_type,
    fe.product_type as fg_product_type,
    p.project_name,
    re.roll_size as roll_size,
    COALESCE(c.client_name, '') as client_name_from_table,
    '' as client_phone,
    '' as client_address
FROM fg_deliveries d
LEFT JOIN fg_entry fe ON d.fg_entry_id = fe.id
LEFT JOIN projects p ON fe.project_id = p.id
LEFT JOIN roll_entry re ON d.reference_number = re.reference_number
LEFT JOIN clients c ON d.client_id = c.id
WHERE d.challan_no = ?
ORDER BY d.id ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $challan_no);
$stmt->execute();
$result = $stmt->get_result();
$deliveries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

if (empty($deliveries)) {
    die("No deliveries found for this challan");
}

// Use first delivery for common fields (client, date, etc.)
$delivery = $deliveries[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Challan - <?php echo htmlspecialchars($challan_no); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Arial', sans-serif; 
            padding: 10px; 
            background: #f5f5f5;
        }
        .challan-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .company-logo {
            max-width: 200px;
            height: auto;
            margin-bottom: 8px;
        }
        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        .company-info {
            font-size: 11px;
            color: #7f8c8d;
            line-height: 1.4;
            margin-bottom: 2px;
        }
        .challan-title {
            font-size: 20px;
            font-weight: bold;
            color: #e74c3c;
            margin-top: 8px;
        }
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        .info-box {
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            background: #f9f9f9;
        }
        .info-box h3 {
            font-size: 13px;
            color: #2c3e50;
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 2px solid #3498db;
        }
        .info-row {
            display: flex;
            margin-bottom: 5px;
            font-size: 12px;
        }
        .info-label {
            font-weight: 600;
            width: 120px;
            color: #555;
        }
        .info-value {
            flex: 1;
            color: #2c3e50;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .items-table th,
        .items-table td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-size: 12px;
        }
        .items-table th {
            background: #34495e;
            color: white;
            font-weight: 600;
            font-size: 13px;
        }
        .items-table td {
            font-size: 13px;
        }
        .total-row {
            background: #ecf0f1;
            font-weight: bold;
        }
        .signature-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            margin-top: 80px;
        }
        .signature-box {
            text-align: center;
        }
        .signature-line {
            border-top: 2px solid #2c3e50;
            margin-top: 60px;
            padding-top: 10px;
            font-size: 13px;
            font-weight: 600;
        }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #7f8c8d;
        }
        .print-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
        .print-btn:hover {
            background: #2980b9;
        }
        @media print {
            @page {
                size: A4;
                margin: 15mm;
            }
            body { 
                background: white; 
                padding: 0; 
            }
            .challan-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .print-btn {
                display: none;
            }
            .items-table {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .items-table thead {
                display: table-header-group;
            }
            .items-table tbody {
                display: table-row-group;
            }
            .items-table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print Challan
    </button>

    <div class="challan-container">
        <div class="header">
            <img src="../assets/images/logo.jpg" alt="GEOCIL Logo" class="company-logo">
            <div class="company-name">Automation</div>
            <div class="company-info" style="margin-top: 5px; font-weight: 600;">Manufacturing & Supply of Geotextile Products</div>
            <div class="company-info">Address: Bahamonbagha, Mohjompur, Sonargoan, Narayanganj</div>
            <div class="company-info">Phone: +88 02 01704119907, 01777741028 | Email: www.confidencegroup.com.bd</div>
            <div class="challan-title">Delivery Challan</div>
        </div>

        <div class="info-section">
            <div class="info-box">
                <h3>Delivery Information</h3>
                <div class="info-row">
                    <div class="info-label">Lighthouse Challan No:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($challan_no); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Delivery ID:</div>
                    <div class="info-value"><?php echo htmlspecialchars($delivery['delivery_id'] ?? 'FD-' . $delivery['id']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date:</div>
                    <div class="info-value"><?php 
                        $deliveryDate = $delivery['delivery_date'];
                        if (!empty($deliveryDate) && $deliveryDate != '0000-00-00' && $deliveryDate != '0000-00-00 00:00:00' && strtotime($deliveryDate)) {
                            echo date('d M, Y g:i A', strtotime($deliveryDate));
                        } else {
                            echo 'N/A';
                        }
                    ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Shift:</div>
                    <div class="info-value"><?php echo htmlspecialchars($delivery['shift'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Truck No:</div>
                    <div class="info-value"><?php echo htmlspecialchars($delivery['truck_no'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Destination:</div>
                    <div class="info-value"><?php 
                        $destination = $delivery['client_address'] ?? $delivery['destination'] ?? '';
                        $clientName = $delivery['client_name'] ?? '';
                        if (empty($destination) || $destination == '0') {
                            $destination = (!empty($clientName) && $clientName != '0') ? $clientName : 'N/A';
                        }
                        echo htmlspecialchars($destination);
                    ?></div>
                </div>
            </div>

            <div class="info-box">
                <h3>Client Information</h3>
                <div class="info-row">
                    <div class="info-label">Client Name:</div>
                    <div class="info-value"><strong><?php 
                        $clientName = $delivery['client_name'] ?? '';
                        echo htmlspecialchars((!empty($clientName) && $clientName != '0') ? $clientName : 'Unknown'); 
                    ?></strong></div>
                </div>
                <?php if (!empty($delivery['client_name_from_table']) && $delivery['client_name_from_table'] !== $delivery['client_name']): ?>
                <div class="info-row">
                    <div class="info-label">Contact Person:</div>
                    <div class="info-value"><?php echo htmlspecialchars($delivery['client_name_from_table'] ?? 'N/A'); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php 
        // Check if any item is a roll to determine if we should show Reference Number column
        $hasRollItems = false;
        foreach ($deliveries as $item) {
            $itemProductType = strtolower(trim($item['delivery_product_type'] ?? $item['fg_product_type'] ?? 'bag'));
            if ($itemProductType === 'roll') {
                $hasRollItems = true;
                break;
            }
        }
        
        $productType = strtolower(trim($delivery['delivery_product_type'] ?? $delivery['fg_product_type'] ?? 'bag'));
        $isRoll = ($productType === 'roll');
        ?>
        <table class="items-table">
            <thead>
                <tr>
                    <th>S.No</th>
                    <?php 
                    // Only show Reference Number column if there are roll items
                    if ($hasRollItems):
                    ?>
                    <th>Reference Number</th>
                    <?php endif; ?>
                    <th><?php echo $isRoll ? 'Roll Size' : 'Bag Size'; ?></th>
                    <?php 
                    // Hide Packaging column for both bags and rolls
                    // Packaging is not needed in challan
                    ?>
                    <th>Quantity</th>
                    <th>Unit Price (৳)</th>
                    <th>Total Cost (৳)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rowNum = 1;
                $grandTotal = 0;
                $unit_price = $delivery['unit_price'] ?? 0; // Use unit price from first delivery
                $deliveryUnit = $delivery['delivery_unit'] ?? 'piece';
                $unitLabel = ($deliveryUnit === 'kg') ? 'kg' : 'pcs';
                
                // Use bag size from first delivery for all items (consistent across same delivery for bags)
                $commonBagSize = $delivery['bag_size'] ?: $delivery['fg_bag_size'] ?: 'N/A';
                
                foreach ($deliveries as $deliveryItem): 
                    $quantity = $deliveryItem['delivery_quantity'] ?? 0;
                    $itemCost = $deliveryItem['total_cost'] ?? ($quantity * $unit_price);
                    $grandTotal += $itemCost;
                    
                    $isItemRoll = ($deliveryItem['delivery_product_type'] ?? 'bag') === 'roll';
                    
                    // For rolls, get roll size from roll_entry (where it's saved); for bags, use common bag size
                    if ($isItemRoll) {
                        $itemSize = $deliveryItem['roll_size'] ?: 'N/A';
                    } else {
                        $itemSize = $commonBagSize;
                    }
                ?>
                <tr>
                    <td><?php echo $rowNum++; ?></td>
                    <?php 
                    // Only show Reference Number for rolls, not for bags
                    if ($hasRollItems):
                    ?>
                    <td><?php echo $isItemRoll ? htmlspecialchars($deliveryItem['reference_number'] ?? 'N/A') : ''; ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($itemSize); ?></td>
                    <?php 
                    // Hide Packaging column for both bags and rolls
                    // Packaging is not needed in challan
                    ?>
                    <td><strong><?php echo number_format($quantity, 2) . ' ' . $unitLabel; ?></strong></td>
                    <td><?php echo number_format($unit_price, 2) . ' / ' . (($deliveryUnit === 'kg') ? 'kg' : 'pc'); ?></td>
                    <td><strong><?php echo number_format($itemCost, 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <?php 
                    // Calculate colspan: S.No (1) + Reference Number (1 if hasRollItems, 0 otherwise) + Size (1) + Quantity (1) + Unit Price (1) = 5 if has rolls, 4 if only bags
                    $colspan = $hasRollItems ? 5 : 4;
                    ?>
                    <td colspan="<?php echo $colspan; ?>" style="text-align: right; font-weight: 700;">Total:</td>
                    <td><strong style="font-size: 14px;">৳ <?php echo number_format($grandTotal, 2); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($delivery['remarks'])): ?>
        <div class="info-box" style="margin-bottom: 30px;">
            <h3>Remarks</h3>
            <div style="margin-top: 10px; font-size: 13px; line-height: 1.6;">
                <?php echo nl2br(htmlspecialchars($delivery['remarks'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">
                    Authorized By<br>
                    (Company Signature & Stamp)
                </div>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    Received By<br>
                    (Client Signature & Stamp)
                </div>
            </div>
        </div>

        <div class="footer">
            <p>This is a computer-generated delivery challan. No signature is required.</p>
            <p>Generated on <?php echo date('d M, Y g:i A'); ?></p>
        </div>
    </div>

    <script>
        // Auto-print on load if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === '1') {
            window.onload = function() {
                setTimeout(() => window.print(), 500);
            };
        }
    </script>
</body>
</html>


