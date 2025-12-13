<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

$page_title = "Print Batch Receipt - CHMSU BAO";
$base_url = "..";

// Get batch ID
if (!isset($_GET['batch_id'])) {
    header("Location: orders.php");
    exit();
}

$batch_id = sanitize_input($_GET['batch_id']);

// Get all orders in this batch
$stmt = $conn->prepare("SELECT o.*, i.name as item_name, i.description, u.name as customer_name, u.email as customer_email
                        FROM orders o
                        JOIN inventory i ON o.inventory_id = i.id
                        JOIN user_accounts u ON o.user_id = u.id
                        WHERE o.batch_id = ?
                        ORDER BY o.id ASC");
$stmt->bind_param("s", $batch_id);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
$total_amount = 0;
$customer_name = '';
$customer_email = '';
$order_date = '';

while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
    $total_amount += $row['total_price'];
    if (empty($customer_name)) {
        $customer_name = $row['customer_name'];
        $customer_email = $row['customer_email'];
        $order_date = $row['created_at'];
    }
}

if (count($orders) === 0) {
    header("Location: orders.php");
    exit();
}

// Generate control number (using batch_id)
$control_no = $batch_id;
$order_date_formatted = date('F j, Y', strtotime($order_date));
$order_time = date('g:i A', strtotime($order_date));

// Official receipt number - empty so cashier can input manually
$receipt_no = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ORDER OF PAYMENT - <?php echo $batch_id; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { 
            size: A4 portrait; 
            margin: 5mm;
            orientation: portrait;
        }
        body { 
            font-family: 'Times New Roman', serif; 
            font-size: 8pt;
            padding: 0;
            margin: 0;
            width: 100%;
            background: white;
        }
        .page {
            width: 100%;
            max-width: 210mm;
            height: 287mm;
            min-height: 287mm;
            max-height: 287mm;
            padding: 5mm;
            margin: 0 auto;
            margin-bottom: 0;
            background: white;
            position: relative;
            page-break-after: always;
            overflow: hidden;
            box-sizing: border-box;
        }
        .page:last-child {
            page-break-after: auto;
            margin-bottom: 0;
        }
        .copies-container {
            display: flex;
            flex-direction: column;
            gap: 0;
            height: 100%;
            width: 100%;
            box-sizing: border-box;
        }
        .copy {
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            padding: 4mm 6mm;
            position: relative;
            width: 100%;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            flex-shrink: 0;
            page-break-inside: avoid;
        }
        .page-1 .copy {
            height: calc((100% - 4px) / 2);
            min-height: 0;
        }
        .page-2 .copy {
            height: calc(100% - 4px);
            min-height: 0;
        }
        .copy:first-child {
            border-top: 2px solid #000;
        }
        .copy:last-child {
            border-bottom: 2px solid #000;
        }
        .copy:not(:first-child):not(:last-child) {
            border-top: 1px dashed #999;
            border-bottom: 1px dashed #999;
        }
        .page-1 .copy:not(:last-child) {
            border-bottom: 1px dashed #999;
        }
        
        /* Header Section - All in one row */
        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 4px;
            gap: 6px;
        }
        .logo {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid #228B22;
            border-radius: 50%;
            padding: 2px;
        }
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .title {
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex: 1;
        }
        .header-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
            flex-shrink: 0;
        }
        .document-info-box {
            border: 1.5px solid #000;
            padding: 3px 5px;
            font-size: 6pt;
            text-align: left;
            min-width: 120px;
            line-height: 1.3;
        }
        .document-info-box div {
            margin: 0;
        }
        .status-box {
            border: 1.5px solid #000;
            padding: 2px 5px;
            font-size: 6pt;
            text-align: center;
            min-width: 70px;
        }
        .status-stamp {
            font-size: 7pt;
            font-weight: bold;
            color: #006400;
            text-transform: uppercase;
        }
        .copy-label-top {
            font-size: 6pt;
            font-weight: bold;
            margin-top: 1px;
            text-align: center;
        }
        
        /* Form Fields Section */
        .form-section {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1fr;
            gap: 8px;
            margin-bottom: 6px;
        }
        .form-left {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-middle {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-right {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-field {
            display: flex;
            align-items: baseline;
            font-size: 7pt;
        }
        .form-label {
            font-weight: bold;
            min-width: 60px;
            margin-right: 3px;
            font-size: 7pt;
        }
        .form-value {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 12px;
            padding: 0 3px 1px;
            font-size: 7pt;
        }
        .form-value-large {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 50px;
            padding: 2px 3px;
            font-size: 6.5pt;
            white-space: pre-line;
        }
        
        /* Payment Section */
        .payment-section {
            margin: 6px 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .payment-header {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 6px;
            margin-bottom: 3px;
            font-weight: bold;
            font-size: 7pt;
            padding-bottom: 2px;
            border-bottom: 2px solid #000;
        }
        .payment-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 6px;
            margin-bottom: 2px;
            min-height: 14px;
            font-size: 6.5pt;
        }
        .payment-nature {
            border-bottom: 1px solid #000;
            padding: 2px 3px;
            min-height: 14px;
            overflow: hidden;
        }
        .payment-amount {
            border-bottom: 1px solid #000;
            padding: 2px 3px;
            text-align: right;
            min-height: 14px;
        }
        .total-box {
            border: 2px solid #000;
            padding: 3px 6px;
            margin: 4px 0;
            font-weight: bold;
            font-size: 8pt;
            text-align: right;
            background: #f9f9f9;
        }
        
        /* Bottom Section */
        .bottom-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 4px;
        }
        .signature-section {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .signature-box {
            display: flex;
            flex-direction: column;
        }
        .signature-line {
            border-bottom: 1.5px solid #000;
            width: 100%;
            margin-bottom: 2px;
            min-height: 22px;
        }
        .signature-label {
            font-size: 6.5pt;
            font-weight: bold;
        }
        .signature-role {
            font-size: 6pt;
            margin-top: 1px;
            color: #333;
        }
        .receipt-section {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .receipt-field {
            display: flex;
            align-items: baseline;
            font-size: 6.5pt;
        }
        .receipt-label {
            font-weight: bold;
            min-width: 75px;
            margin-right: 3px;
            font-size: 6.5pt;
        }
        .receipt-value {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 12px;
            padding: 0 3px 1px;
            font-size: 6.5pt;
        }
        
        .copy-label {
            position: absolute;
            bottom: 4mm;
            left: 6mm;
            font-size: 6pt;
            font-weight: bold;
        }
        
        .no-print {
            text-align: center;
            padding: 20px;
            background: #f3f4f6;
        }
        .print-button {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            margin: 10px;
        }
        .print-button:hover {
            background: #2563eb;
        }
        
        @media print {
            .no-print { display: none !important; }
            script { display: none !important; }
            * {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            html, body { 
                padding: 0; 
                margin: 0;
                width: 100%;
                height: 574mm;
                max-height: 574mm;
                overflow: hidden;
            }
            @page {
                size: A4 portrait;
                margin: 5mm;
                orientation: portrait;
            }
            .page { 
                margin: 0; 
                padding: 5mm;
                border: none;
                width: 100%;
                height: 287mm;
                min-height: 287mm;
                max-height: 287mm;
                max-width: 100%;
                page-break-after: always;
                break-after: page;
                page-break-inside: avoid;
                break-inside: avoid;
                overflow: hidden;
                box-sizing: border-box;
            }
            .page.page-2 {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            body > .page:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            body::after {
                content: "";
                display: none;
                page-break-after: avoid;
            }
            html::after {
                content: "";
                display: none;
            }
            .copies-container {
                gap: 0;
                height: 100%;
                box-sizing: border-box;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .copy {
                border-top: 1px solid #000;
                border-bottom: 1px solid #000;
                page-break-inside: avoid;
                break-inside: avoid;
                page-break-after: avoid;
                break-after: avoid;
                page-break-before: avoid;
                break-before: avoid;
            }
            .copy:first-child {
                border-top: 2px solid #000;
                page-break-before: avoid;
                break-before: avoid;
            }
            .copy:last-child {
                border-bottom: 2px solid #000;
                page-break-after: avoid;
                break-after: avoid;
            }
            .copy:not(:first-child):not(:last-child) {
                border-top: 1px dashed #000;
                border-bottom: 1px dashed #000;
            }
            .page-1 .copy:not(:last-child) {
                border-bottom: 1px dashed #000;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-button" onclick="window.location.href='orders.php'">
            ← Back to Orders
        </button>
        <button class="print-button" onclick="window.print()">
            🖨️ Print Receipt
        </button>
    </div>

    <!-- Page 1: Business Affairs Office's Copy and Cashier's Copy -->
    <div class="page page-1">
        <div class="copies-container">
            <?php 
            $page1_copies = [
                ['name' => "Business Affairs Office's Copy", 'class' => ''],
                ['name' => "Cashier's Copy", 'class' => '']
            ];
            
            foreach ($page1_copies as $copy): 
            ?>
            <div class="copy">
                <!-- Header Section - All in one row -->
                <div class="header-top">
                    <div class="logo">
                        <img src="../image/CHMSUWebLOGO.png" alt="CHMSU Logo">
                    </div>
                    <div class="title">ORDER OF PAYMENT</div>
                    <div class="header-right">
                        <div class="document-info-box">
                            <div>Document Code: F.04-BAO-CHMSU</div>
                            <div>Revision No.: 0</div>
                            <div>Page: 1 of 2</div>
                        </div>
                        <div class="copy-label-top"><?php echo $copy['name']; ?></div>
                        <div class="status-box">
                            <div style="font-weight: bold; margin-bottom: 2px;">STATUS</div>
                            <div class="status-stamp">PENDING</div>
                        </div>
                    </div>
                </div>

                <!-- Form Fields Section -->
                <div class="form-section">
                        <div class="form-left">
                        <div class="form-field">
                            <span class="form-label">Control No.:</span>
                            <span class="form-value"><?php echo htmlspecialchars($control_no); ?></span>
                        </div>
                        <div class="form-field">
                            <span class="form-label">Date:</span>
                            <span class="form-value"><?php echo $order_date_formatted; ?> Time: <?php echo $order_time; ?></span>
                        </div>
                    </div>
                    <div class="form-middle">
                        <div class="form-field">
                            <span class="form-label">Payor:</span>
                            <span class="form-value"><?php echo htmlspecialchars($customer_name); ?></span>
                        </div>
                        <div style="margin-top: 4px;">
                            <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px;">NATURE OF PAYMENT</div>
                            <div class="form-value-large">
                                <?php 
                                $payment_nature = [];
                                foreach ($orders as $order) {
                        $item_desc = htmlspecialchars($order['item_name']);
                        if (!empty($order['size'])) {
                            $item_desc .= ' (Size: ' . htmlspecialchars($order['size']) . ')';
                        }
                                    $payment_nature[] = $order['quantity'] . 'x ' . $item_desc;
                        }
                                echo implode("\n", $payment_nature);
                    ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-right">
                        <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px; text-align: center;">AMOUNT</div>
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <?php foreach ($orders as $order): ?>
                            <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                            ₱<?php echo number_format($order['total_price'], 2); ?>
                            </div>
                            <?php endforeach; ?>
                            <!-- Fill remaining space -->
                            <?php for ($i = count($orders); $i < 3; $i++): ?>
                            <div style="border-bottom: 1px solid #000; padding: 2px 3px; min-height: 14px;"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                    
                <!-- Total -->
                    <div class="total-box">
                        TOTAL: ₱<?php echo number_format($total_amount, 2); ?>
                </div>

                <!-- Receipt Info Section -->
                <div class="receipt-section" style="margin-bottom: 6px;">
                    <div class="receipt-field">
                        <span class="receipt-label">Official Receipt No.:</span>
                        <span class="receipt-value"><?php echo $receipt_no; ?></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Date:</span>
                        <span class="receipt-value"><?php echo $order_date_formatted; ?> Time: <?php echo $order_time; ?></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value">₱<?php echo number_format($total_amount, 2); ?></span>
                    </div>
                </div>

                <!-- Bottom Section - Signatures Aligned -->
                <div class="bottom-section">
                <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Issued by:</div>
                        <div class="signature-role">Business Affairs Clerk</div>
                    </div>
                    </div>
                    <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Payment Received by:</div>
                        <div class="signature-role">Cashier Clerk</div>
                    </div>
                </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Page 2: Customer's Copy -->
    <div class="page page-2">
        <div class="copies-container">
            <?php 
            $page2_copies = [
                ['name' => "Customer's Copy", 'class' => '']
            ];
            
            foreach ($page2_copies as $copy): 
            ?>
            <div class="copy">
                <!-- Header Section - All in one row -->
                <div class="header-top">
                    <div class="logo">
                        <img src="../image/CHMSUWebLOGO.png" alt="CHMSU Logo">
                    </div>
                    <div class="title">ORDER OF PAYMENT</div>
                    <div class="header-right">
                        <div class="document-info-box">
                            <div>Document Code: F.04-BAO-CHMSU</div>
                            <div>Revision No.: 0</div>
                            <div>Page: 2 of 2</div>
                        </div>
                        <div class="copy-label-top"><?php echo $copy['name']; ?></div>
                        <div class="status-box">
                            <div style="font-weight: bold; margin-bottom: 2px;">STATUS</div>
                            <div class="status-stamp">PENDING</div>
                        </div>
                    </div>
                </div>

                <!-- Form Fields Section -->
                <div class="form-section">
                        <div class="form-left">
                        <div class="form-field">
                            <span class="form-label">Control No.:</span>
                            <span class="form-value"><?php echo htmlspecialchars($control_no); ?></span>
                        </div>
                        <div class="form-field">
                            <span class="form-label">Date:</span>
                            <span class="form-value"><?php echo $order_date_formatted; ?> Time: <?php echo $order_time; ?></span>
                        </div>
                    </div>
                    <div class="form-middle">
                        <div class="form-field">
                            <span class="form-label">Payor:</span>
                            <span class="form-value"><?php echo htmlspecialchars($customer_name); ?></span>
                        </div>
                        <div style="margin-top: 4px;">
                            <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px;">NATURE OF PAYMENT</div>
                            <div class="form-value-large">
                                <?php 
                                $payment_nature = [];
                                foreach ($orders as $order) {
                        $item_desc = htmlspecialchars($order['item_name']);
                        if (!empty($order['size'])) {
                            $item_desc .= ' (Size: ' . htmlspecialchars($order['size']) . ')';
                        }
                                    $payment_nature[] = $order['quantity'] . 'x ' . $item_desc;
                        }
                                echo implode("\n", $payment_nature);
                    ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-right">
                        <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px; text-align: center;">AMOUNT</div>
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <?php foreach ($orders as $order): ?>
                            <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                            ₱<?php echo number_format($order['total_price'], 2); ?>
                            </div>
                            <?php endforeach; ?>
                            <!-- Fill remaining space -->
                            <?php for ($i = count($orders); $i < 3; $i++): ?>
                            <div style="border-bottom: 1px solid #000; padding: 2px 3px; min-height: 14px;"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                    
                <!-- Total -->
                    <div class="total-box">
                        TOTAL: ₱<?php echo number_format($total_amount, 2); ?>
                </div>

                <!-- Receipt Info Section -->
                <div class="receipt-section" style="margin-bottom: 6px;">
                    <div class="receipt-field">
                        <span class="receipt-label">Official Receipt No.:</span>
                        <span class="receipt-value"><?php echo $receipt_no; ?></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Date:</span>
                        <span class="receipt-value"><?php echo $order_date_formatted; ?> Time: <?php echo $order_time; ?></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value">₱<?php echo number_format($total_amount, 2); ?></span>
                    </div>
                </div>

                <!-- Bottom Section - Signatures Aligned -->
                <div class="bottom-section">
                <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Issued by:</div>
                        <div class="signature-role">Business Affairs Clerk</div>
                    </div>
                    </div>
                    <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Payment Received by:</div>
                        <div class="signature-role">Cashier Clerk</div>
                    </div>
                </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        window.onload = function() {
            // Optional: Auto-print when page loads
            // window.print();
        }
    </script>
    <style>
        @media print {
            html {
                height: 574mm !important;
                max-height: 574mm !important;
                overflow: hidden !important;
            }
            body {
                height: 574mm !important;
                max-height: 574mm !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .page.page-2 {
                page-break-after: avoid !important;
                break-after: avoid !important;
            }
            .page:last-of-type {
                page-break-after: avoid !important;
                break-after: avoid !important;
            }
        }
    </style>
</body>
</html>
