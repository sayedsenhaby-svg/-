document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.getElementById('project-select');
    let gantt;

    // Fetch projects and populate the dropdown
    fetch('api.php?resource=projects')
        .then(response => response.json())
        .then(projects => {
            if (projects.length > 0) {
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name;
                    projectSelect.appendChild(option);
                });
                // Load tasks for the first project by default
                loadTasks(projects[0].id);
            } else {
                document.getElementById('gantt').innerHTML = '<p>لا توجد مشاريع لعرضها.</p>';
            }
        });

    // Event listener for project selection
    projectSelect.addEventListener('change', function () {
        const projectId = this.value;
        loadTasks(projectId);
    });

    function loadTasks(projectId) {
        fetch(`api.php?resource=tasks&project_id=${projectId}`)
            .then(response => response.json())
            .then(tasks => {
                // Clear previous Gantt chart
                document.getElementById('gantt').innerHTML = '';

                if (tasks.length > 0) {
                    gantt = new Gantt("#gantt", tasks, {
                        language: 'ar',
                        view_mode: 'Day',
                        date_format: 'YYYY-MM-DD'
                    });
                } else {
                    document.getElementById('gantt').innerHTML = '<p>لا توجد مهام لهذا المشروع.</p>';
                }
            });
    }
});