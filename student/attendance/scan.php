<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'Scan Attendance';
$studentPageCss = 'student-attendance.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

$barcodeToken = $studentProfile['barcode_token'];
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div>
            <h2>My Attendance Barcode</h2>
            <p>Present this barcode at an authorized attendance station for an event in your department.</p>
        </div>
        <button class="management-button management-button-secondary" type="button" onclick="window.print()">Print barcode</button>
    </div>
    <article class="student-content-panel student-barcode-card">
        <p><strong><?= studentEscape(studentName($studentProfile)) ?></strong> · <?= studentEscape($studentProfile['student_number']) ?></p>
        <svg id="studentBarcode" aria-label="Your unique student attendance barcode"></svg>
        <p class="student-barcode-fallback"><?= studentEscape($barcodeToken) ?></p>
        <p class="student-muted">This barcode identifies only your student account. It cannot directly change attendance; an authorized staff member must scan it at the event attendance station.</p>
        <div id="barcodeError" class="management-alert" role="alert" hidden>Barcode display could not be loaded. Show the token above to an authorized attendance staff member.</div>
    </article>
</section>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
    (() => {
        const token = <?= json_encode($barcodeToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const barcode = document.getElementById('studentBarcode');
        try {
            if (!window.JsBarcode || !barcode) {
                throw new Error('Barcode renderer is unavailable.');
            }
            window.JsBarcode(barcode, token, {
                format: 'CODE128',
                displayValue: true,
                fontSize: 15,
                height: 90,
                margin: 12
            });
        } catch (error) {
            document.getElementById('barcodeError').hidden = false;
        }
    })();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>