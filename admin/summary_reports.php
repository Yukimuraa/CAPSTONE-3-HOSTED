<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

require_admin();

$page_title = "Summary Reports - CHMSU BAO";
$base_url = "..";

$mode = isset($_GET['mode']) ? sanitize_input($_GET['mode']) : 'daily'; // daily, monthly, yearly
$date = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');
$month = isset($_GET['month']) ? sanitize_input($_GET['month']) : date('Y-m');
$year = isset($_GET['year']) ? (int)sanitize_input($_GET['year']) : (int)date('Y');

// Compute ranges
if ($mode === 'daily') {
    $start = $date; $end = $date;
} elseif ($mode === 'monthly') {
    $start = $month.'-01'; $end = date('Y-m-t', strtotime($start));
} else { // yearly
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
}

// Requests submitted (gym bookings + bus schedules + orders + general requests)
$count_bookings = (int)$conn->query("SELECT COUNT(*) as c FROM bookings WHERE facility_type='gym' AND DATE(created_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
$count_bus = (int)$conn->query("SELECT COUNT(*) as c FROM bus_schedules WHERE DATE(date_covered) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
$count_requests = (int)$conn->query("SELECT COUNT(*) as c FROM requests WHERE DATE(created_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;

// Approvals
$approved_bookings = (int)$conn->query("SELECT COUNT(*) as c FROM bookings WHERE facility_type='gym' AND (status='confirmed' OR status='approved') AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
$approved_bus = (int)$conn->query("SELECT COUNT(*) as c FROM bus_schedules WHERE status='approved' AND DATE(date_covered) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;

// Orders (Item Sales)
$count_orders = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;
$completed_orders = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE status='completed' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['c'] ?? 0;

// Total requests includes all services
$total_requests = $count_bookings + $count_bus + $count_orders + $count_requests;

// Approvals
$approved_total = $approved_bookings + $approved_bus + $completed_orders;

// Collections (orders completed + bus payments paid + gym bookings paid)
$col_orders = (float)($conn->query("SELECT SUM(total_price) as s FROM orders WHERE status='completed' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['s'] ?? 0);
$col_bus = (float)($conn->query("SELECT SUM(total_amount) as s FROM billing_statements WHERE payment_status='paid' AND DATE(payment_date) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'")->fetch_assoc()['s'] ?? 0);

// Gym collections: sum total from cost_breakdown in additional_info for confirmed bookings with or_number
$gym_collections_query = "SELECT additional_info FROM bookings WHERE facility_type='gym' AND (status='confirmed' OR status='approved') AND or_number IS NOT NULL AND or_number != '' AND DATE(updated_at) BETWEEN '".$conn->real_escape_string($start)."' AND '".$conn->real_escape_string($end)."'";
$gym_collections_result = $conn->query($gym_collections_query);
$col_gym = 0.0;
if ($gym_collections_result) {
    while ($row = $gym_collections_result->fetch_assoc()) {
        if (!empty($row['additional_info'])) {
            $additional_info = json_decode($row['additional_info'], true);
            if (isset($additional_info['cost_breakdown']['total'])) {
                $col_gym += (float)$additional_info['cost_breakdown']['total'];
            }
        }
    }
}

$collected_total = $col_orders + $col_gym;

// Most requested service (by count)
$svc = [ 'Gym' => $count_bookings, 'Bus' => $count_bus, 'Item Sales' => $count_orders, 'Requests' => $count_requests ];
arsort($svc); $top_service = key($svc);
?>

<?php include '../includes/header.php'; ?>

<div class="flex h-screen bg-gray-100">
	<?php include '../includes/admin_sidebar.php'; ?>
	<div class="flex-1 flex flex-col overflow-hidden">
		<header class="bg-white shadow-sm z-10">
			<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-900"><?php echo $mode==='daily' ? 'Daily' : ($mode==='monthly' ? 'Monthly' : 'Yearly'); ?> Transaction Summary</h1>
				<div class="flex items-center">
					<span class="text-gray-700 mr-2"><?php echo $_SESSION['user_name']; ?></span>
					<button class="md:hidden rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500" id="menu-button">
						<span class="sr-only">Open menu</span>
						<i class="fas fa-bars"></i>
					</button>
				</div>
			</div>
		</header>

		<main class="flex-1 overflow-y-auto p-4">
			<div class="max-w-7xl mx-auto">
				<!-- Filters -->
				<div class="bg-white rounded-lg shadow p-6 mb-6">
					<h2 class="text-lg font-semibold text-gray-900 mb-4">Filter</h2>
					<form method="GET" action="summary_reports.php" class="grid grid-cols-1 md:grid-cols-6 gap-4">
						<div>
							<label class="block text-sm text-gray-700 mb-1">Mode</label>
                            <select name="mode" class="w-full rounded-md border-gray-300" onchange="toggleInputs(this.value)">
								<option value="daily" <?php echo $mode==='daily'?'selected':''; ?>>Daily</option>
								<option value="monthly" <?php echo $mode==='monthly'?'selected':''; ?>>Monthly</option>
                                <option value="yearly" <?php echo $mode==='yearly'?'selected':''; ?>>Yearly</option>
							</select>
						</div>
                        <div id="dateWrap" class="<?php echo ($mode!=='daily')?'hidden':''; ?>">
							<label class="block text-sm text-gray-700 mb-1">Date</label>
							<input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" class="w-full rounded-md border-gray-300">
						</div>
                        <div id="monthWrap" class="<?php echo ($mode!=='monthly')?'hidden':''; ?>">
							<label class="block text-sm text-gray-700 mb-1">Month</label>
							<input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>" class="w-full rounded-md border-gray-300">
						</div>
                        <div id="yearWrap" class="<?php echo ($mode!=='yearly')?'hidden':''; ?>">
                            <label class="block text-sm text-gray-700 mb-1">Year</label>
                            <input type="number" min="2000" max="2100" step="1" name="year" value="<?php echo htmlspecialchars($year); ?>" class="w-full rounded-md border-gray-300">
                        </div>
						<div class="md:col-span-3 flex items-end">
							<button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700"><i class="fas fa-filter mr-2"></i>Apply</button>
						</div>
					</form>
				</div>

				<!-- KPIs -->
				<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
					<div class="bg-white rounded-lg shadow p-4"><p class="text-gray-500 text-sm">Requests Submitted</p><h3 class="text-2xl font-bold"><?php echo number_format($total_requests); ?></h3></div>
					<div class="bg-white rounded-lg shadow p-4"><p class="text-gray-500 text-sm">Approved</p><h3 class="text-2xl font-bold text-green-600"><?php echo number_format($approved_total); ?></h3></div>
					<div class="bg-white rounded-lg shadow p-4"><p class="text-gray-500 text-sm">Collected</p><h3 class="text-2xl font-bold text-emerald-600">₱<?php echo number_format($collected_total,2); ?></h3></div>
					<div class="bg-white rounded-lg shadow p-4"><p class="text-gray-500 text-sm">Most Requested</p><h3 class="text-2xl font-bold text-purple-600"><?php echo $top_service; ?></h3></div>
				</div>

				<!-- Breakdown table -->
				<div class="bg-white rounded-lg shadow">
					<div class="px-4 py-5 border-b border-gray-200 sm:px-6 flex justify-between items-center">
						<h3 class="text-lg font-medium text-gray-900">Breakdown</h3>
						<div class="flex gap-2">
							<a href="download_report.php?type=summary&format=pdf&<?php echo http_build_query($_GET); ?>" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700">
								<i class="fas fa-file-pdf mr-1"></i> PDF
							</a>
							<a href="download_report.php?type=summary&format=excel&<?php echo http_build_query($_GET); ?>" class="bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700">
								<i class="fas fa-file-excel mr-1"></i> Excel
							</a>
						</div>
					</div>
					<div class="overflow-x-auto">
						<table class="min-w-full divide-y divide-gray-200">
							<thead class="bg-gray-50">
								<tr>
									<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service</th>
									<th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Requests</th>
									<th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Approved</th>
									<th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Collected</th>
								</tr>
							</thead>
							<tbody class="bg-white divide-y divide-gray-200">
								<tr>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">Gym</td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500"><?php echo number_format($count_bookings); ?></td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500"><?php echo number_format($approved_bookings); ?></td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">₱<?php echo number_format($col_gym, 2); ?></td>
								</tr>
								<tr>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">Item Sales</td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500"><?php echo number_format($count_orders); ?></td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500"><?php echo number_format($completed_orders); ?></td>
									<td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500">₱<?php echo number_format($col_orders,2); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</main>
	</div>
</div>

<script>
    function toggleInputs(val){
        document.getElementById('dateWrap').classList.toggle('hidden', val!=='daily');
        document.getElementById('monthWrap').classList.toggle('hidden', val!=='monthly');
        document.getElementById('yearWrap').classList.toggle('hidden', val!=='yearly');
    }
	document.getElementById('menu-button').addEventListener('click', function() {
		document.getElementById('sidebar').classList.toggle('-translate-x-full');
	});
</script>

    <script src="<?php echo $base_url ?? ''; ?>/assets/js/main.js"></script>
</body>
</html>


