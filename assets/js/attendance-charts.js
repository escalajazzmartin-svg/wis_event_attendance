document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded.');
        return;
    }

    /* ==========================================
       SHARED HELPERS AND UNIFORM CHART DESIGN
    ========================================== */

    function getColors() {
        const styles = getComputedStyle(document.documentElement);

        return {
            present: '#1e8b39',
            late: '#F2C300',
            absent: '#641414',
            excused: '#5985c5',
            text: styles.getPropertyValue('--wis-black').trim() || '#171717',
            border: styles.getPropertyValue('--wis-border').trim() || '#B8C1CC'
        };
    }

    function normalize(value) {
        return String(value ?? '').trim().toLowerCase();
    }

    function destroyExistingChart(canvas) {
        const existingChart = Chart.getChart(canvas);

        if (existingChart) {
            existingChart.destroy();
        }
    }

    function getAxisStep(max) {
        if (max <= 5) return 1;
        if (max <= 10) return 2;
        if (max <= 25) return 5;
        if (max <= 50) return 10;
        if (max <= 100) return 20;
        if (max <= 250) return 50;
        if (max <= 500) return 100;
        if (max <= 1000) return 200;

        return Math.pow(10, Math.floor(Math.log10(max))) / 5;
    }

    function createUniformAttendanceChart(canvas, labels, values, colors) {
        destroyExistingChart(canvas);

        return new Chart(canvas, {
            type: 'bar',

            data: {
                labels: labels,

                datasets: [{
                    label: 'Attendance Records',
                    data: values,

                    backgroundColor: [
                        colors.present,
                        colors.late,
                        colors.absent,
                        colors.excused
                    ],

                    borderWidth: 0,
                    categoryPercentage: 0.7,
                    barPercentage: 0.5,
                    maxBarThickness: 45
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                scales: {
                    x: {
                        grid: {
                            display: false
                        },

                        ticks: {
                            color: colors.text,
                            font: {
                                size: 11
                            }
                        },

                        border: {
                            color: colors.border
                        }
                    },

                    y: {
                        beginAtZero: true,

                        afterDataLimits: function (axis) {
                            const values = axis.chart.data.datasets.flatMap(
                                function (dataset) {
                                    return dataset.data.map(Number);
                                }
                            );

                            const largest = Math.max(0, ...values);
                            const step = getAxisStep(largest);
                            const max = Math.max(
                                5,
                                Math.ceil(largest / step) * step
                            );

                            axis.options.min = 0;
                            axis.options.max = max;
                            axis.$attendanceStep = step;
                        },

                        afterBuildTicks: function (axis) {
                            const step = axis.$attendanceStep || 1;
                            const ticks = [];

                            for (
                                let value = 0;
                                value <= axis.max;
                                value += step
                            ) {
                                ticks.push({
                                    value: value
                                });
                            }

                            axis.ticks = ticks;
                        },

                        ticks: {
                            autoSkip: false,
                            precision: 0,
                            color: colors.text,

                            font: {
                                size: 11
                            }
                        },

                        grid: {
                            color: colors.border
                        },

                        border: {
                            display: false
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const value = Number(context.raw || 0);

                                const total = context.chart.data.datasets[0]
                                    .data.reduce(function (sum, item) {
                                        return sum + Number(item || 0);
                                    }, 0);

                                const percentage = total > 0
                                    ? (value / total * 100).toFixed(1)
                                    : '0.0';

                                return context.label + ': ' +
                                    value + ' (' + percentage + '%)';
                            },

                            footer: function (items) {
                                const total = items.length
                                    ? items[0].chart.data.datasets[0]
                                        .data.reduce(function (sum, value) {
                                            return sum + Number(value || 0);
                                        }, 0)
                                    : 0;

                                return 'Total attendance records: ' + total;
                            }
                        }
                    }
                }
            }
        });
    }

    const STATUS_LABELS = [
        'Present',
        'Late',
        'Absent',
        'Excused'
    ];

    function toStatusValues(counts) {
        return [
            Number(counts.present || 0),
            Number(counts.late || 0),
            Number(counts.absent || 0),
            Number(counts.excused || 0)
        ];
    }

    /* ==========================================
       STUDENT ATTENDANCE CHART
       Canvas: attendanceStatusChart
    ========================================== */

    function initializeStudentChart() {
        const canvas = document.getElementById('attendanceStatusChart');

        if (!canvas) return;

        const academicPeriodFilter =
            document.getElementById('academicPeriodFilter');

        const events = Array.isArray(window.attendanceEvents)
            ? window.attendanceEvents
            : [];

        const colors = getColors();

        let chart;

        function semesterOrder(semester) {
            const value = normalize(semester);

            if (value.includes('1st') || value === '1') return 3;
            if (value.includes('2nd') || value === '2') return 2;
            if (value.includes('summer')) return 1;

            return 0;
        }

        function formatSemester(semester) {
            const value = normalize(semester);

            if (value.includes('1st') || value === '1') {
                return '1st Sem';
            }

            if (value.includes('2nd') || value === '2') {
                return '2nd Sem';
            }

            if (value.includes('summer')) {
                return 'Summer';
            }

            return semester || 'Other';
        }

        function populateAcademicPeriods() {
            if (!academicPeriodFilter) return;

            const currentValue = academicPeriodFilter.value;
            const periods = new Map();

            events.forEach(function (event) {
                if (event.academicPeriodId == null) return;

                const id = String(event.academicPeriodId);

                if (!periods.has(id)) {
                    periods.set(id, {
                        id: id,
                        year: String(event.academicYear || ''),
                        semester: event.semester || ''
                    });
                }
            });

            const sortedPeriods = Array.from(periods.values()).sort(
                function (a, b) {
                    return b.year.localeCompare(
                        a.year,
                        undefined,
                        { numeric: true }
                    ) || semesterOrder(b.semester) -
                        semesterOrder(a.semester);
                }
            );

            academicPeriodFilter.innerHTML = '';

            const allOption = document.createElement('option');
            allOption.value = 'all';
            allOption.textContent = 'All';

            academicPeriodFilter.appendChild(allOption);

            sortedPeriods.forEach(function (period) {
                const option = document.createElement('option');

                option.value = period.id;

                option.textContent = period.year +
                    ' (' + formatSemester(period.semester) + ')';

                academicPeriodFilter.appendChild(option);
            });

            academicPeriodFilter.value = sortedPeriods.some(
                function (period) {
                    return period.id === currentValue;
                }
            ) ? currentValue : 'all';
        }

        function getFilteredEvents() {
            const selectedPeriod = academicPeriodFilter
                ? academicPeriodFilter.value
                : 'all';

            return events.filter(function (event) {
                return selectedPeriod === 'all' ||
                    String(event.academicPeriodId) === selectedPeriod;
            });
        }

        function countStatuses(filteredEvents) {
            return filteredEvents.reduce(
                function (counts, event) {
                    counts.present += Number(event.present || 0);
                    counts.late += Number(event.late || 0);
                    counts.absent += Number(event.absent || 0);
                    counts.excused += Number(event.excused || 0);

                    return counts;
                },
                {
                    present: 0,
                    late: 0,
                    absent: 0,
                    excused: 0
                }
            );
        }

        function updateStatusSummary(counts) {
            const elements = {
                present: document.getElementById('presentCount'),
                late: document.getElementById('lateCount'),
                absent: document.getElementById('absentCount'),
                excused: document.getElementById('excusedCount')
            };

            Object.keys(elements).forEach(function (key) {
                if (elements[key]) {
                    elements[key].textContent = counts[key];
                }
            });
        }

        function updateChart() {
            const counts = countStatuses(getFilteredEvents());
            const values = toStatusValues(counts);

            if (!chart) {
                chart = createUniformAttendanceChart(
                    canvas,
                    STATUS_LABELS,
                    values,
                    colors
                );
            } else {
                chart.data.labels = STATUS_LABELS;
                chart.data.datasets[0].data = values;
                chart.update();
            }

            updateStatusSummary(counts);
        }

        populateAcademicPeriods();

        if (academicPeriodFilter) {
            academicPeriodFilter.addEventListener('change', updateChart);
        }

        updateChart();
    }

    /* ==========================================
       DEPARTMENT HEAD ATTENDANCE CHART
       Canvas: departmentAttendanceChart
    ========================================== */

    function initializeDepartmentHeadChart() {
        const canvas = document.getElementById(
            'departmentAttendanceChart'
        );

        if (!canvas) return;

        const attendance = window.departmentAttendance || {};
        const colors = getColors();

        const counts = {
            present: Number(attendance.present || 0),
            late: Number(attendance.late || 0),
            absent: Number(attendance.absent || 0),
            excused: Number(attendance.excused || 0)
        };

        createUniformAttendanceChart(
            canvas,
            STATUS_LABELS,
            toStatusValues(counts),
            colors
        );
    }

    /* ==========================================
       INITIALIZE CHARTS FOR THE CURRENT PAGE
    ========================================== */

    initializeStudentChart();
    initializeDepartmentHeadChart();
});