<?php

$periodRow = $pdo->query(
    'SELECT academic_year, semester FROM academic_periods WHERE is_current = 1 LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$periodLabel = $periodRow
    ? $periodRow['semester'] . ' Semester, ' . $periodRow['academic_year']
    : '';
?>
<footer class="admin-footer">
    <span><strong>WEAMMS</strong> · Westmead International School</span>
    <span>Department Head Portal<?= $periodLabel !== '' ? ' · ' . dhEscape($periodLabel) : '' ?></span>
</footer>
</main>
</body>

</html>
