
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('attendanceStatusChart');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    const academicPeriodFilter = document.getElementById(
        'academicPeriodFilter'
    );

    const events = Array.isArray(window.attendanceEvents)
        ? window.attendanceEvents
        : [];

    const rootStyles = getComputedStyle(document.documentElement);

    const colors = {
        present: '#1e8b39',
        late: '#F2C300',
        absent: '#641414',
        text: rootStyles.getPropertyValue('--wis-black').trim() || '#171717',
        border: rootStyles.getPropertyValue('--wis-border').trim() || '#B8C1CC'
    };

    // Normalize values for consistent comparisons.
    function normalize(value) {
        return String(value ?? '').trim().toLowerCase();
    }

    // Sort semesters in academic order.
    function semesterOrder(semester) {
        const value = normalize(semester);

        if (value.includes('1st') || value === '1') return 3;
        if (value.includes('2nd') || value === '2') return 2;
        if (value.includes('summer')) return 1;

        return 0;
    }

    function formatSemester(semester) {
        const value = normalize(semester);

        if (value.includes('1st') || value === '1') return '1st Sem';
        if (value.includes('2nd') || value === '2') return '2nd Sem';
        if (value.includes('summer')) return 'Summer';

        return semester || 'Other';
    }

    // Populate academic-period dropdown using available event data.
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
                return b.year.localeCompare(a.year, undefined, {
                    numeric: true
                }) ||
                    semesterOrder(b.semester) - semesterOrder(a.semester);
            }
        );

        academicPeriodFilter.innerHTML = '';

        const allOption = document.createElement('option');
        allOption.value = 'all';
        allOption.textContent = 'All Academic Periods';
        academicPeriodFilter.appendChild(allOption);

        sortedPeriods.forEach(function (period) {
            const option = document.createElement('option');

            option.value = period.id;
            option.textContent =
                `${period.year} (${formatSemester(period.semester)})`;

            academicPeriodFilter.appendChild(option);
        });

        academicPeriodFilter.value =
            sortedPeriods.some(period => period.id === currentValue)
                ? currentValue
                : 'all';
    }

    // Return events matching the selected academic period.
    function getFilteredEvents() {
        const selectedPeriod = academicPeriodFilter
            ? academicPeriodFilter.value
            : 'all';

        return events.filter(function (event) {
            return selectedPeriod === 'all' ||
                String(event.academicPeriodId) === selectedPeriod;
        });
    }

    // Count each attendance status.
    function countStatuses(filteredEvents) {
        return filteredEvents.reduce(function (counts, event) {
            counts.present += Number(event.present || 0);
            counts.late += Number(event.late || 0);
            counts.absent += Number(event.absent || 0);

            return counts;
        }, {
            present: 0,
            late: 0,
            absent: 0
        });
    }

    // Choose readable Y-axis intervals based on the maximum value.
    function getYAxisStep(max) {
        if (max <= 5) return 1;    // 0, 1, 2, 3, 4, 5
        if (max <= 10) return 2;   // 0, 2, 4, 6, 8, 10
        if (max <= 25) return 5;   // 0, 5, 10, 15, 20, 25
        if (max <= 50) return 10;  // 0, 10, 20, 30, 40, 50
        if (max <= 100) return 20; // 0, 20, 40, 60, 80, 100
        if (max <= 250) return 50;
        if (max <= 500) return 100;
        if (max <= 1000) return 200;

        const magnitude = Math.pow(10, Math.floor(Math.log10(max)));
        return magnitude / 5;
    }

    // Create the stacked bar chart.
    const chart = new Chart(canvas, {
        type: 'bar',

        data: {
            labels: ['Attendance'],
            datasets: [
                {
                    label: 'Present',
                    data: [0],
                    backgroundColor: colors.present,
                    borderColor: colors.present,
                    borderWidth: 0,
                    borderRadius: 0,
                    stack: 'attendance',
                    barThickness: 100,
                    maxBarThickness: 100
                },
                {
                    label: 'Late',
                    data: [0],
                    backgroundColor: colors.late,
                    borderColor: colors.late,
                    borderWidth: 0,
                    borderRadius: 0,
                    stack: 'attendance',
                    barThickness: 100,
                    maxBarThickness: 100
                },
                {
                    label: 'Absent',
                    data: [0],
                    backgroundColor: colors.absent,
                    borderColor: colors.absent,
                    borderWidth: 0,
                    borderRadius: 0,
                    stack: 'attendance',
                    barThickness: 100,
                    maxBarThickness: 100
                }
            ]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'x',

            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: colors.text
                    },
                    border: {
                        color: colors.border
                    }
                },


                y: {
                    stacked: true,
                    beginAtZero: true,

                    // Determine the axis maximum before calculating ticks.
                    afterDataLimits: function (axis) {
                        const total = axis.chart.data.datasets.reduce(
                            function (sum, dataset) {
                                return sum + Number(dataset.data[0] || 0);
                            },
                            0
                        );

                        let step;
                        let max;

                        if (total <= 5) {
                            step = 1;
                            max = 5;
                        } else if (total <= 10) {
                            step = 2;
                            max = 10;
                        } else if (total <= 25) {
                            step = 5;
                            max = Math.ceil(total / step) * step;
                        } else if (total <= 50) {
                            step = 10;
                            max = Math.ceil(total / step) * step;
                        } else if (total <= 100) {
                            step = 20;
                            max = Math.ceil(total / step) * step;
                        } else {
                            step = Math.pow(10, Math.floor(Math.log10(total))) / 2;
                            max = Math.ceil(total / step) * step;
                        }

                        axis.min = 0;
                        axis.max = max;
                        axis.options.min = 0;
                        axis.options.max = max;

                        // Save the selected interval for tick generation.
                        axis.$attendanceStep = step;
                    },

                    afterBuildTicks: function (axis) {
                        const step = axis.$attendanceStep || 1;
                        const max = axis.max;
                        const ticks = [];

                        for (let value = 0; value <= max; value += step) {
                            ticks.push({ value: value });
                        }

                        axis.ticks = ticks;
                    },

                    ticks: {
                        autoSkip: false,
                        precision: 0,
                        color: colors.text
                    },

                    grid: {
                        color: colors.border
                    },

                    border: {
                        display: false
                    }
                },

            },

            plugins: {
                legend: {
                    display: false
                },

                tooltip: {
                    callbacks: {
                        label: function (context) {
                            const value = Number(context.raw || 0);

                            const total =
                                context.chart.data.datasets.reduce(
                                    function (sum, dataset) {
                                        return sum + Number(
                                            dataset.data[context.dataIndex] || 0
                                        );
                                    },
                                    0
                                );

                            const percentage = total > 0
                                ? (value / total * 100).toFixed(1)
                                : '0.0';

                            return `${context.dataset.label}: ${value} (${percentage}%)`;
                        },

                        footer: function (items) {
                            const total = items.reduce(
                                (sum, item) =>
                                    sum + Number(item.raw || 0),
                                0
                            );

                            return 'Total attendance records: ' + total;
                        }
                    }
                }
            }
        }
    });

    // Update the numbers displayed beside the chart.
    function updateStatusSummary(counts) {
        const presentElement = document.getElementById('presentCount');
        const lateElement = document.getElementById('lateCount');
        const absentElement = document.getElementById('absentCount');

        if (presentElement) {
            presentElement.textContent = counts.present;
        }

        if (lateElement) {
            lateElement.textContent = counts.late;
        }

        if (absentElement) {
            absentElement.textContent = counts.absent;
        }
    }

    // Refresh the chart whenever the selected period changes.
    function updateChart() {
        const counts = countStatuses(getFilteredEvents());

        chart.data.datasets[0].data = [counts.present];
        chart.data.datasets[1].data = [counts.late];
        chart.data.datasets[2].data = [counts.absent];

        chart.update();
        updateStatusSummary(counts);
    }

    populateAcademicPeriods();

    if (academicPeriodFilter) {
        academicPeriodFilter.addEventListener('change', updateChart);
    }

    updateChart();
});
