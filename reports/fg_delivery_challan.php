<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user'];
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

// Fetch delivery details from new fg_deliveries table
// For rolls, bag_size in fg_entry contains the roll_size
$query = "SELECT 
    d.*,
    fe.bag_size as fg_bag_size,
    fe.packaging_type as fg_packaging_type,
    fe.product_type as fg_product_type,
    p.project_name,
    COALESCE(c.client_name, c.name, '') as client_name_from_table,
    '' as client_phone,
    '' as client_address
FROM fg_deliveries d
LEFT JOIN fg_entry fe ON d.fg_entry_id = fe.id
LEFT JOIN projects p ON fe.project_id = p.id
LEFT JOIN clients c ON d.client_id = c.id
WHERE d.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param('i', $delivery_id);
$stmt->execute();
$result = $stmt->get_result();
$delivery = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$delivery) {
    die("Delivery not found");
}

$challan_no = $delivery['challan_no'] ?? $delivery['delivery_id'] ?? 'CH-' . str_pad($delivery_id, 5, '0', STR_PAD_LEFT);
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
            body { 
                background: white; 
                padding: 0; 
            }
            .challan-container {
                box-shadow: none;
                padding: 20px;
            }
            .print-btn {
                display: none;
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

        <table class="items-table">
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>Reference Number</th>
                    <th>Project</th>
                    <?php 
                    $productType = strtolower(trim($delivery['delivery_product_type'] ?? $delivery['fg_product_type'] ?? 'bag'));
                    $isRoll = ($productType === 'roll');
                    ?>
                    <th><?php echo $isRoll ? 'Roll Size' : 'Bag Size'; ?></th>
                    <?php if (!$isRoll): ?>
                    <th>Packaging</th>
                    <?php endif; ?>
                    <th>Quantity</th>
                    <th>Unit Price (৳)</th>
                    <th>Total Cost (৳)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $unit_price = $delivery['unit_price'] ?? 0;
                $quantity = $delivery['delivery_quantity'] ?? 0;
                $deliveryUnit = $delivery['delivery_unit'] ?? 'piece';
                $unitLabel = ($deliveryUnit === 'kg') ? 'kg' : 'pcs';
                $total_cost = $delivery['total_cost'] ?? ($quantity * $unit_price);
                
                // Get size based on product type
                // For rolls, bag_size in fg_entry contains the roll_size
                // For bags, bag_size contains the bag size
                $size = $delivery['bag_size'] ?: $delivery['fg_bag_size'] ?: 'N/A';
                ?>
                <tr>
                    <td>1</td>
                    <td><?php echo htmlspecialchars($delivery['reference_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($delivery['project_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($size); ?></td>
                    <?php if (!$isRoll): ?>
                    <td><?php echo htmlspecialchars($delivery['packaging_type'] ?: $delivery['fg_packaging_type'] ?: 'N/A'); ?></td>
                    <?php endif; ?>
                    <td><strong><?php echo number_format($quantity, 2) . ' ' . $unitLabel; ?></strong></td>
                    <td><?php echo number_format($unit_price, 2) . ' / ' . (($deliveryUnit === 'kg') ? 'kg' : 'pc'); ?></td>
                    <td><strong><?php echo number_format($total_cost, 2); ?></strong></td>
                </tr>
                <tr class="total-row">
                    <td colspan="<?php echo $isRoll ? '6' : '7'; ?>" style="text-align: right; font-weight: 700;">Total:</td>
                    <td><strong style="font-size: 14px;">৳ <?php echo number_format($total_cost, 2); ?></strong></td>
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


