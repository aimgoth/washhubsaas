                <!-- /.container-fluid -->
            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white mt-auto">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; WashHub <?php echo date('Y'); ?></span>
                    </div>
                </div>
            </footer>
            <!-- End of Footer -->
        </div>
        <!-- End of Content Wrapper -->
    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <!-- Logout Modal-->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button class="close" type="button" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <a class="btn btn-primary" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script>
        // Toggle the side navigation
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar
            document.getElementById('sidebarToggle').addEventListener('click', function(e) {
                e.preventDefault();
                document.body.classList.toggle('sidebar-toggled');
                document.querySelector('.sidebar').classList.toggle('toggled');
                
                if (document.querySelector('.sidebar').classList.contains('toggled')) {
                    var sidebarCollapse = document.querySelectorAll('.sidebar .collapse');
                    sidebarCollapse.forEach(function(collapse) {
                        var bsCollapse = new bootstrap.Collapse(collapse, {
                            toggle: false
                        });
                        bsCollapse.hide();
                    });
                }
            });

            // Close any open menu accordions when window is resized below 768px
            window.addEventListener('resize', function() {
                if (window.innerWidth < 768) {
                    var sidebarCollapse = document.querySelectorAll('.sidebar .collapse');
                    sidebarCollapse.forEach(function(collapse) {
                        var bsCollapse = new bootstrap.Collapse(collapse, {
                            toggle: false
                        });
                        bsCollapse.hide();
                    });
                }
            });

            // Prevent the content wrapper from scrolling when the fixed side navigation hovered over
            var sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.addEventListener('mousewheel', function(e) {
                    if (window.innerWidth > 768) {
                        var delta = e.deltaY || -e.detail;
                        this.scrollTop += (delta < 0 ? 1 : -1) * 30;
                        e.preventDefault();
                    }
                });
            }

            // Scroll to top button appear
            var scrollToTop = document.querySelector('.scroll-to-top');
            if (scrollToTop) {
                window.addEventListener('scroll', function() {
                    if (window.pageYOffset > 100) {
                        scrollToTop.style.display = 'block';
                    } else {
                        scrollToTop.style.display = 'none';
                    }
                });
            }

            // Smooth scrolling using jQuery easing
            var scrollToTopLink = document.querySelector('a.scroll-to-top');
            if (scrollToTopLink) {
                scrollToTopLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    var target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        window.scrollTo({
                            top: target.offsetTop,
                            behavior: 'smooth'
                        });
                    }
                });
            }
        });
    </script>
</body>
</html>
