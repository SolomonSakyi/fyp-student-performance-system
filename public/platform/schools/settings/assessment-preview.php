<!-- Preview Tab -->
<div class="tab-content active">
    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-eye me-2 text-primary"></i>Preview Calculation</h6>
            <span class="text-muted" style="font-size:12px;">Test your assessment configuration with sample data</span>
        </div>
        <div class="card-body-custom">
            <form id="previewForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Student Name</label>
                            <input type="text" class="form-control" id="previewStudentName" placeholder="Sample Student" value="Kwame Mensah">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <label class="form-label">Assessment Profile</label>
                            <select class="form-select" id="previewProfile">
                                <option value="">Select Profile</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label">Subject Scores</label>
                    <div id="previewSubjectsContainer">
                        <div class="row mb-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" placeholder="Subject" value="Mathematics">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" placeholder="CA Score" value="25">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" placeholder="Exam Score" value="62">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removePreviewSubject(this)"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control form-control-sm" placeholder="Subject" value="English">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" placeholder="CA Score" value="28">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control form-control-sm" placeholder="Exam Score" value="65">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removePreviewSubject(this)"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addPreviewSubject()">
                        <i class="fas fa-plus me-1"></i> Add Subject
                    </button>
                </div>

                <hr>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-primary" onclick="runPreview()">
                        <i class="fas fa-play me-2"></i> Run Preview
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearPreview()">
                        <i class="fas fa-undo me-2"></i> Clear
                    </button>
                </div>
            </form>

            <!-- Preview Results -->
            <div id="previewResults" style="display:none;margin-top:24px;">
                <hr>
                <h6 class="mb-3"><i class="fas fa-chart-bar me-2 text-success"></i>Preview Results</h6>
                <div id="previewResultsContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
    // ============================================================
    // ADD PREVIEW SUBJECT
    // ============================================================
    function addPreviewSubject() {
        var container = document.getElementById('previewSubjectsContainer');
        var html = `
        <div class="row mb-2">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" placeholder="Subject">
            </div>
            <div class="col-md-3">
                <input type="number" class="form-control form-control-sm" placeholder="CA Score">
            </div>
            <div class="col-md-3">
                <input type="number" class="form-control form-control-sm" placeholder="Exam Score">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removePreviewSubject(this)"><i class="fas fa-times"></i></button>
            </div>
        </div>
    `;
        container.insertAdjacentHTML('beforeend', html);
    }

    // ============================================================
    // REMOVE PREVIEW SUBJECT
    // ============================================================
    function removePreviewSubject(btn) {
        var row = btn.closest('.row');
        if (row && document.querySelectorAll('#previewSubjectsContainer .row').length > 1) {
            row.remove();
        }
    }

    // ============================================================
    // RUN PREVIEW
    // ============================================================
    function runPreview() {
        var results = document.getElementById('previewResults');
        var content = document.getElementById('previewResultsContent');

        var subjects = document.querySelectorAll('#previewSubjectsContainer .row');
        var totalScore = 0;
        var subjectResults = [];

        subjects.forEach(function(row) {
            var inputs = row.querySelectorAll('input');
            var name = inputs[0]?.value || 'Unknown';
            var caScore = parseFloat(inputs[1]?.value) || 0;
            var examScore = parseFloat(inputs[2]?.value) || 0;
            var total = caScore + examScore;
            totalScore += total;

            var grade = total >= 80 ? 'A' : total >= 70 ? 'B' : total >= 60 ? 'C' : total >= 50 ? 'D' : 'F';
            var gradePoint = total >= 80 ? 1 : total >= 70 ? 2 : total >= 60 ? 3 : total >= 50 ? 4 : 9;

            subjectResults.push({
                name: name,
                caScore: caScore,
                examScore: examScore,
                total: total,
                grade: grade,
                gradePoint: gradePoint
            });
        });

        var average = subjects.length > 0 ? totalScore / subjects.length : 0;
        var overallGrade = average >= 80 ? 'A' : average >= 70 ? 'B' : average >= 60 ? 'C' : average >= 50 ? 'D' : 'F';
        var aggregate = subjectResults.reduce(function(sum, s) {
            return sum + s.gradePoint;
        }, 0);

        var html = `
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>CA Score</th>
                        <th>Exam Score</th>
                        <th>Total</th>
                        <th>Grade</th>
                        <th>Grade Point</th>
                    </tr>
                </thead>
                <tbody>
        `;

        subjectResults.forEach(function(s) {
            var badgeClass = s.grade === 'A' ? 'success' : s.grade === 'B' ? 'primary' : s.grade === 'C' ? 'info' : s.grade === 'D' ? 'warning' : 'danger';
            html += `
                <tr>
                    <td><strong>${s.name}</strong></td>
                    <td>${s.caScore}</td>
                    <td>${s.examScore}</td>
                    <td>${s.total}</td>
                    <td><span class="badge bg-${badgeClass}">${s.grade}</span></td>
                    <td>${s.gradePoint}</td>
                </tr>
            `;
        });

        html += `
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold;">
                        <td colspan="3" class="text-end">Summary:</td>
                        <td>Average: ${average.toFixed(1)}%</td>
                        <td>Overall: ${overallGrade}</td>
                        <td>Aggregate: ${aggregate}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="alert alert-info mt-2">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Sample Calculation:</strong> This is a preview based on the current assessment configuration.
            ${aggregate <= 12 ? 'Excellent performance! 🎉' : aggregate <= 24 ? 'Good performance! 👍' : 'Consider improvement in some areas. 📚'}
        </div>
    `;

        content.innerHTML = html;
        results.style.display = 'block';
    }

    // ============================================================
    // CLEAR PREVIEW
    // ============================================================
    function clearPreview() {
        document.getElementById('previewResults').style.display = 'none';
        showAlert('Preview cleared', 'info');
    }

    // ============================================================
    // INITIALIZE
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        var select = document.getElementById('previewProfile');
        select.innerHTML = `
        <option value="">Select Profile</option>
        <option value="1" selected>JHS WAEC Profile</option>
        <option value="2">Primary Standard Profile</option>
    `;
    });
</script>