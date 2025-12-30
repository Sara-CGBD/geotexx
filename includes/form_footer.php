        </div>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');

        function toggleSidebar() {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('active');
            
            if (sidebar.classList.contains('show')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = 'auto';
            }
        }

        function toggleGroup(header) {
            const subMenu = header.nextElementSibling;
            const toggleIcon = header.querySelector('.toggle-icon');

            // Close other open submenus
            document.querySelectorAll('.menu-group .sub-menu').forEach(menu => {
                if (menu !== subMenu && (menu.style.display === 'block' || menu.classList.contains('show'))) {
                    menu.style.display = 'none';
                    menu.classList.remove('show');
                    menu.previousElementSibling.classList.remove('active');
                    const icon = menu.previousElementSibling.querySelector('.toggle-icon');
                    if (icon) {
                        icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
                    }
                }
            });

            // Toggle current submenu
            if (subMenu.style.display === 'block' || subMenu.classList.contains('show')) {
                subMenu.style.display = 'none';
                subMenu.classList.remove('show');
                header.classList.remove('active');
                toggleIcon.classList.replace('fa-chevron-up', 'fa-chevron-down');
            } else {
                subMenu.style.display = 'block';
                subMenu.classList.add('show');
                header.classList.add('active');
                toggleIcon.classList.replace('fa-chevron-down', 'fa-chevron-up');
            }
        }

        // Close sidebar when clicking overlay
        sidebarOverlay.addEventListener('click', () => {
            toggleSidebar();
        });

        // Close sidebar when clicking nav links
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
    </script>
</body>
</html>

