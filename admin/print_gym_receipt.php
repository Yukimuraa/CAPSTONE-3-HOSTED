<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
require_admin();

$page_title = "Print Gym Booking Receipt - CHMSU BAO";
$base_url = "..";

// Get booking ID
if (!isset($_GET['booking_id'])) {
    header("Location: gym_bookings.php");
    exit();
}

$booking_id = sanitize_input($_GET['booking_id']);

// Get booking details
$stmt = $conn->prepare("SELECT b.*, u.name as user_name, u.email as user_email, u.organization 
                        FROM bookings b
                        JOIN user_accounts u ON b.user_id = u.id
                        WHERE b.booking_id = ? AND b.facility_type = 'gym'");
$stmt->bind_param("s", $booking_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: gym_bookings.php");
    exit();
}

$booking = $result->fetch_assoc();

// Parse additional_info JSON
$additional_info = [];
if (!empty($booking['additional_info'])) {
    $additional_info = json_decode($booking['additional_info'], true);
}

// Extract cost breakdown
$cost_breakdown = isset($additional_info['cost_breakdown']) ? $additional_info['cost_breakdown'] : [];
$total_cost = isset($cost_breakdown['total']) ? $cost_breakdown['total'] : 0;

// Get facility name if facility_id exists in additional_info
$facility_name = "Gymnasium"; // Default
if (isset($additional_info['facility_id']) && !empty($additional_info['facility_id'])) {
    $facility_id = intval($additional_info['facility_id']);
    $facility_stmt = $conn->prepare("SELECT name FROM gym_facilities WHERE id = ?");
    $facility_stmt->bind_param("i", $facility_id);
    $facility_stmt->execute();
    $facility_result = $facility_stmt->get_result();
    if ($facility_result->num_rows > 0) {
        $facility_row = $facility_result->fetch_assoc();
        $facility_name = $facility_row['name'];
    }
}

// Generate control number (using booking_id)
$control_no = $booking_id;
$booking_date_formatted = date('F j, Y', strtotime($booking['date']));
$booking_time = date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time']));

// Format submission time (when booking was created)
$submission_time = '';
if (!empty($booking['created_at'])) {
    $submission_time = date('g:i A', strtotime($booking['created_at']));
}

// Generate official receipt number
$receipt_no = 'OR-GYM-' . date('Ymd') . '-' . substr($booking_id, -6);

// Calculate hours
$start = new DateTime($booking['start_time']);
$end = new DateTime($booking['end_time']);
$hours = $start->diff($end)->h + ($start->diff($end)->i / 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ORDER OF PAYMENT - <?php echo $booking_id; ?></title>
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
            padding: 5mm;
            margin: 0 auto;
            background: white;
            position: relative;
        }
        .copies-container {
            display: flex;
            flex-direction: column;
            gap: 0;
            height: 100%;
            width: 100%;
        }
        .copy {
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            padding: 4mm 6mm;
            position: relative;
            width: 100%;
            display: flex;
            flex-direction: column;
            height: calc((100% - 4px) / 3);
            box-sizing: border-box;
            flex-shrink: 0;
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
            min-height: 14px;
            padding: 2px 3px;
            font-size: 6.5pt;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
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
            body { 
                padding: 0; 
                margin: 0;
                width: 100%;
            }
            .page { 
                margin: 0; 
                padding: 10mm;
                border: none;
                width: 100%;
                max-width: 100%;
            }
            .copies-container {
                gap: 0;
            }
            .copy {
                border-top: 1px solid #000;
                border-bottom: 1px solid #000;
            }
            .copy:first-child {
                border-top: 2px solid #000;
            }
            .copy:last-child {
                border-bottom: 2px solid #000;
            }
            .copy:not(:first-child):not(:last-child) {
                border-top: 1px dashed #000;
                border-bottom: 1px dashed #000;
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
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-button" onclick="window.location.href='gym_management.php'">
            ← Back to Gym Management
        </button>
        <button class="print-button" onclick="window.print()">
            🖨️ Print Receipt
        </button>
    </div>

    <div class="page">
        <div class="copies-container">
            <?php 
            $copies = [
                ['name' => "Business Affairs Office's Copy", 'class' => ''],
                ['name' => "Cashier's Copy", 'class' => ''],
                ['name' => "Customer's Copy", 'class' => '']
            ];
            
            foreach ($copies as $copy): 
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
                            <div>Page: 1 of 1</div>
                        </div>
                        <div class="copy-label-top"><?php echo $copy['name']; ?></div>
                        <div class="status-box">
                            <div style="font-weight: bold; margin-bottom: 2px;">STATUS</div>
                            <div class="status-stamp"><?php echo strtoupper($booking['status']); ?></div>
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
                            <span class="form-value"><?php echo $booking_date_formatted; ?><?php if (!empty($submission_time)): ?> Time: <?php echo $submission_time; ?><?php endif; ?></span>
                        </div>
                    </div>
                    <div class="form-middle">
                        <div class="form-field">
                            <span class="form-label">Payor:</span>
                            <span class="form-value"><?php echo htmlspecialchars($booking['user_name']); ?></span>
                        </div>
                        <?php if (!empty($booking['organization'])): ?>
                        <div class="form-field">
                            <span class="form-label">Organization:</span>
                            <span class="form-value"><?php echo htmlspecialchars($booking['organization']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 4px;">
                            <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px;">NATURE OF PAYMENT</div>
                            <div class="form-value-large">
                                <?php 
                                $payment_nature = [];
                                $payment_nature[] = "Facility: " . htmlspecialchars($facility_name);
                                $payment_nature[] = "Date: " . $booking_date_formatted;
                                $payment_nature[] = "Time: " . $booking_time;
                                $payment_nature[] = "Duration: " . number_format($hours, 2) . " hours";
                                $payment_nature[] = "Purpose: " . htmlspecialchars($booking['purpose']);
                                $payment_nature[] = "Attendees: " . $booking['attendees'];
                                if (!empty($additional_info['organization'])) {
                                    $payment_nature[] = "Organization: " . htmlspecialchars($additional_info['organization']);
                                }
                                if (!empty($additional_info['equipment'])) {
                                    $payment_nature[] = "Equipment: " . htmlspecialchars($additional_info['equipment']);
                                }
                                echo implode(", ", $payment_nature);
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-right">
                        <div style="font-weight: bold; font-size: 7pt; margin-bottom: 2px; text-align: center;">AMOUNT</div>
                        <div style="display: flex; flex-direction: column; gap: 2px;">
                            <?php if (!empty($cost_breakdown)): ?>
                                <?php if ($cost_breakdown['gymnasium'] > 0): ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($cost_breakdown['gymnasium'], 2); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($cost_breakdown['sound_system']) && $cost_breakdown['sound_system'] > 0): ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($cost_breakdown['sound_system'], 2); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($cost_breakdown['electricity']) && $cost_breakdown['electricity'] > 0): ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($cost_breakdown['electricity'], 2); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($cost_breakdown['led_wall']) && $cost_breakdown['led_wall'] > 0): ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($cost_breakdown['led_wall'], 2); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($cost_breakdown['chairs']) && $cost_breakdown['chairs'] > 0): ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($cost_breakdown['chairs'], 2); ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="border-bottom: 1px solid #000; padding: 2px 3px; text-align: right; min-height: 14px; font-size: 6.5pt;">
                                    ₱<?php echo number_format($total_cost, 2); ?>
                                </div>
                            <?php endif; ?>
                            <!-- Fill remaining space -->
                            <?php 
                            $item_count = 0;
                            if (!empty($cost_breakdown)) {
                                if ($cost_breakdown['gymnasium'] > 0) $item_count++;
                                if (isset($cost_breakdown['sound_system']) && $cost_breakdown['sound_system'] > 0) $item_count++;
                                if (isset($cost_breakdown['electricity']) && $cost_breakdown['electricity'] > 0) $item_count++;
                                if (isset($cost_breakdown['led_wall']) && $cost_breakdown['led_wall'] > 0) $item_count++;
                                if (isset($cost_breakdown['chairs']) && $cost_breakdown['chairs'] > 0) $item_count++;
                            } else {
                                $item_count = 1;
                            }
                            for ($i = $item_count; $i < 3; $i++): 
                            ?>
                            <div style="border-bottom: 1px solid #000; padding: 2px 3px; min-height: 14px;"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                    
                <!-- Total -->
                <div class="total-box">
                    TOTAL: ₱<?php echo number_format($total_cost, 2); ?>
                </div>

                <!-- Receipt Info Section -->
                <div class="receipt-section" style="margin-bottom: 6px;">
                    <div class="receipt-field">
                        <span class="receipt-label">Official Receipt No.:</span>
                        <span class="receipt-value"></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Date:</span>
                        <span class="receipt-value"><?php echo $booking_date_formatted; ?><?php if (!empty($submission_time)): ?> Time: <?php echo $submission_time; ?><?php endif; ?></span>
                    </div>
                    <div class="receipt-field">
                        <span class="receipt-label">Amount:</span>
                        <span class="receipt-value">₱<?php echo number_format($total_cost, 2); ?></span>
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
</body>
</html>

