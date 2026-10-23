<?php

$studentPageTitle = 'Student Events';
$studentPageCss = 'student-events.css';

require_once __DIR__ . '/../../includes/student_context.php';

/* ========================================
   FILTER INPUTS
======================================== */

$search = trim(
    is_string($_GET['q'] ?? null) ? $_GET['q'] : ''
);

$phase = is_string($_GET['phase'] ?? null)
    ? $_GET['phase']
    : '';

$validPhases = ['upcoming', 'ongoing', 'past'];

if (!in_array($phase, $validPhases, true)) {
    $phase = '';
}

$academicPeriod = trim(
    is_string($_GET['academic_period'] ?? null)
        ? $_GET['academic_period']
        : ''
);

/* ========================================
   ACADEMIC PERIOD OPTIONS
======================================== */

$periodStmt = $pdo->query("
    SELECT DISTINCT academic_year, semester
    FROM academic_periods
    ORDER BY academic_year DESC, semester
");

$periodOptions = [];

foreach ($periodStmt->fetchAll(PDO::FETCH_ASSOC) as $period) {
    $key = $period['academic_year'] . '|' . $period['semester'];
    $periodOptions[$key] = $period;
}

if (
    $academicPeriod !== ''
    && !isset($periodOptions[$academicPeriod])
) {
    $academicPeriod = '';
}

/* ========================================
   EVENT QUERY
======================================== */

$sql = "
    SELECT
        e.event_id,
        e.event_title,
        e.event_description,
        e.venue,
        e.start_at,
        e.end_at,
        e.event_status,
        e.updated_at,
        e.created_at,
        e.admin_remarks,
        e.cancellation_request_remarks,
        ap.academic_year,
        ap.semester
    FROM events e
    INNER JOIN academic_periods ap
        ON ap.academic_period_id = e.academic_period_id
    WHERE e.department_id = ?
      AND e.event_status IN (
          'approved',
          'cancellation_requested',
          'cancelled',
          'completed'
      )
      AND " . studentParticipantCondition('e');

$params = array_merge(
    [$studentDepartmentId],
    studentParticipantParams($studentProfile)
);

/* Academic period */
if ($academicPeriod !== '') {
    [$selectedYear, $selectedSemester] = explode(
        '|',
        $academicPeriod,
        2
    );

    $sql .= "
        AND ap.academic_year = ?
        AND ap.semester = ?
    ";

    $params[] = $selectedYear;
    $params[] = $selectedSemester;
}

/* Search */
if ($search !== '') {
    $sql .= "
        AND (
            e.event_title LIKE ?
            OR e.event_description LIKE ?
            OR e.venue LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    array_push($params, $like, $like, $like);
}

/* Schedule */
if ($phase === 'upcoming') {
    $sql .= "
        AND e.event_status = 'approved'
        AND e.start_at > NOW()
    ";
} elseif ($phase === 'ongoing') {
    $sql .= "
        AND e.event_status = 'approved'
        AND e.start_at <= NOW()
        AND e.end_at >= NOW()
    ";
} elseif ($phase === 'past') {
    $sql .= "
        AND (
            e.event_status IN ('completed', 'cancelled')
            OR (
                e.event_status = 'approved'
                AND e.end_at < NOW()
            )
        )
    ";
}

$sql .= " ORDER BY e.start_at DESC, e.event_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========================================
   PAGE
======================================== */

require_once __DIR__ . '/../../includes/student_layout_start.php';
?>

<section class="dashboard-section student-module-page student-events-page">

    <div class="section-heading">
        <div>
            <h2>Event Schedules</h2>
            <p>
                Events for
                <?= studentEscape($studentProfile['department_name']) ?>
                and your participation group.
            </p>
        </div>
    </div>

    <!-- FILTERS -->

    <form
        class="student-filter-form"
        id="student-event-filters"
        method="get"
        action="<?= studentEscape($studentBaseUrl) ?>/student/events/index.php">
        <div class="student-filter-field student-search-field">
            <label class="visually-hidden" for="event-search">
                Search events
            </label>
            <input
                id="event-search"
                type="search"
                name="q"
                value="<?= studentEscape($search) ?>"
                placeholder="Search events..."
                aria-label="Search events"
                autocomplete="off">
        </div>

        <div class="student-filter-field student-phase-field">
            <label class="visually-hidden" for="event-phase">
                Schedule
            </label>
            <select
                id="event-phase"
                name="phase"
                aria-label="Schedule">
                <option value="">All Schedules</option>
                <option value="upcoming" <?= $phase === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="ongoing" <?= $phase === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                <option value="past" <?= $phase === 'past' ? 'selected' : '' ?>>Past</option>
            </select>
        </div>

        <div class="student-filter-field student-period-field">
            <label class="visually-hidden" for="academic-period">
                Academic Period
            </label>
            <select
                id="academic-period"
                name="academic_period"
                aria-label="Academic Period">
                <option value="">All Academic Periods</option>

                <?php foreach ($periodOptions as $key => $period): ?>
                    <option
                        value="<?= studentEscape($key) ?>"
                        <?= $academicPeriod === $key ? 'selected' : '' ?>>
                        <?= studentEscape(
                            $period['academic_year'] . ' / ' . $period['semester']
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <noscript>
            <button type="submit" class="management-button">
                Apply Filters
            </button>
        </noscript>
    </form>


    <!-- RESULTS COUNT -->
    <p class="student-results-count">
        <?= count($events) ?>
        matching <?= count($events) === 1 ? 'event' : 'events' ?>
    </p>

    <!-- EVENT LIST -->
    <?php if (empty($events)): ?>

        <div class="student-content-panel empty-state">
            <strong>No matching events</strong>
            <p>
                Try another filter or check back later for relevant events.
            </p>
        </div>

    <?php else: ?>

        <div class="student-event-list student-event-grid">

            <?php foreach ($events as $event): ?>

                <?php
                $eventStatus = $event['event_status'];
                $now = time();
                $start = strtotime($event['start_at']);
                $end = strtotime($event['end_at']);

                $schedulePhase = $eventStatus === 'approved'
                    ? (
                        $start > $now
                        ? 'Upcoming'
                        : ($end >= $now ? 'Ongoing' : 'Past')
                    )
                    : ucwords(str_replace('_', ' ', $eventStatus));

                $eventUrl = studentEscape($studentBaseUrl)
                    . '/student/events/view.php?id='
                    . (int) $event['event_id'];
                ?>

                <article class="student-event-card student-schedule-card">

                    <div class="student-schedule-top">
                        <span class="student-schedule-date">
                            <?= studentEscape(
                                studentDateTime(
                                    $event['start_at'],
                                    'M j · g:i A'
                                )
                            ) ?>
                        </span>

                        <span class="status-pill status-<?= studentEscape($eventStatus) ?>">
                            <?= studentEscape($schedulePhase) ?>
                        </span>
                    </div>

                    <h3 class="student-schedule-title">
                        <a href="<?= $eventUrl ?>">
                            <?= studentEscape($event['event_title']) ?>
                        </a>
                    </h3>

                    <div class="student-schedule-meta">
                        <span class="student-schedule-venue">
                            <?= studentEscape(
                                $event['venue'] ?: 'Venue to be announced'
                            ) ?>
                        </span>

                        <span class="student-schedule-period">
                            <?= studentEscape($event['academic_year']) ?>
                            / <?= studentEscape($event['semester']) ?>
                        </span>
                    </div>

                    <?php if ($eventStatus === 'cancellation_requested'): ?>

                        <p class="student-event-notice">
                            Cancellation requested:
                            <?= studentEscape(
                                $event['cancellation_request_remarks']
                                    ?: 'Awaiting administrator review.'
                            ) ?>
                        </p>

                    <?php elseif (
                        in_array(
                            $eventStatus,
                            ['cancelled', 'completed'],
                            true
                        ) && !empty($event['admin_remarks'])
                    ): ?>

                        <p class="student-event-notice">
                            <?= studentEscape($event['admin_remarks']) ?>
                        </p>

                    <?php elseif (
                        !empty($event['updated_at'])
                        && strtotime($event['updated_at']) >
                        strtotime($event['created_at'] ?? $event['updated_at'])
                    ): ?>

                        <p class="student-event-notice">
                            Schedule updated
                            <?= studentEscape(
                                studentDateTime($event['updated_at'])
                            ) ?>.
                        </p>

                    <?php endif; ?>

                    <a
                        class="student-schedule-details"
                        href="<?= $eventUrl ?>">View event details →</a>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<script>
    (() => {
        const form = document.getElementById('student-event-filters');
        const search = document.getElementById('event-search');

        if (!form || !search) return;

        const phase = document.getElementById('event-phase');
        const period = document.getElementById('academic-period');

        let debounceTimer;
        let controller;
        let lastRequest = 0;

        function updateFilters(immediate = false) {
            clearTimeout(debounceTimer);

            debounceTimer = setTimeout(() => {
                const params = new URLSearchParams(new FormData(form));
                const url = new URL(form.action, window.location.href);

                params.forEach((value, key) => {
                    if (value.trim() === '') {
                        params.delete(key);
                    }
                });

                url.search = params.toString();

                // Avoid refreshing when the selected filters have not changed.
                if (url.href === window.location.href) return;

                // Keep the URL synchronized with the current filters.
                history.replaceState({}, '', url);

                const requestId = ++lastRequest;

                if (controller) controller.abort();
                controller = new AbortController();

                fetch(url.href, {
                        signal: controller.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) throw new Error('Unable to filter events.');
                        return response.text();
                    })
                    .then(html => {
                        if (requestId !== lastRequest) return;

                        const doc = new DOMParser().parseFromString(html, 'text/html');

                        const newResults = doc.querySelector(
                            '.student-event-list, .student-content-panel.empty-state'
                        );

                        const currentResults = document.querySelector(
                            '.student-event-list, .student-content-panel.empty-state'
                        );

                        const newCount = doc.querySelector('.student-results-count');
                        const currentCount = document.querySelector('.student-results-count');

                        if (newResults && currentResults) {
                            currentResults.replaceWith(newResults);
                        } else if (newResults) {
                            const section = document.querySelector('.student-events-page');
                            const filters = document.getElementById('student-event-filters');
                            if (section && filters) {
                                filters.insertAdjacentElement('afterend', newResults);
                            }
                        }

                        if (newCount && currentCount) {
                            currentCount.replaceWith(newCount);
                        }
                    })
                    .catch(error => {
                        if (error.name !== 'AbortError') {
                            // Fall back to normal navigation if filtering fails.
                            window.location.href = url.href;
                        }
                    });
            }, immediate ? 0 : 350);
        }

        search.addEventListener('input', () => updateFilters());
        phase.addEventListener('change', () => updateFilters(true));
        period.addEventListener('change', () => updateFilters(true));

        form.addEventListener('submit', event => {
            event.preventDefault();
            updateFilters(true);
        });
    })();
</script>


<?php require __DIR__ . '/../../includes/footer.php'; ?>
</main>
</body>

</html>