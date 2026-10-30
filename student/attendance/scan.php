<?php

require_once __DIR__ . '/../../includes/student_context.php';

$studentPageTitle = 'My QR Code';
$studentPageCss = 'student-attendance.css';

require_once __DIR__ . '/../../includes/student_layout_start.php';

// The QR code encodes the same unique 64-character token that was previously
// shown as a barcode (students.barcode_token), so existing tokens stay valid.
$qrToken = $studentProfile['barcode_token'];
?>

<section class="dashboard-section student-module-page">
    <div class="section-heading">
        <div>
            <span class="student-eyebrow">Student · Attendance</span>
            <h2>My Attendance QR Code</h2>
            <p>Show this QR code to an authorized attendance station for an event in your department.</p>
        </div>
        <button class="management-button management-button-secondary" type="button" onclick="window.print()">Print QR code</button>
    </div>
    <article class="student-content-panel student-qr-card">
        <p class="student-qr-owner"><strong><?= studentEscape(studentName($studentProfile)) ?></strong> · <?= studentEscape($studentProfile['student_number']) ?></p>
        <div id="studentQr" class="student-qr-image" role="img" aria-label="Your unique student attendance QR code"></div>
        <p class="student-qr-fallback"><?= studentEscape($qrToken) ?></p>
        <p class="student-muted">This QR code identifies only your student account. It cannot directly change attendance; an authorized staff member must scan it at the event attendance station.</p>
        <div id="qrError" class="management-alert" role="alert" hidden>The QR code could not be displayed. Show the code above to an authorized attendance staff member.</div>
    </article>
</section>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<script>
    (() => {
        const token = <?= json_encode($qrToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const target = document.getElementById('studentQr');
        try {
            if (typeof window.qrcode !== 'function' || !target) {
                throw new Error('QR renderer is unavailable.');
            }
            const qr = window.qrcode(0, 'M');
            qr.addData(token);
            qr.make();
            target.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
        } catch (error) {
            document.getElementById('qrError').hidden = false;
        }
    })();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>
