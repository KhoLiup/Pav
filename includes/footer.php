<!-- includes/footer.php -->
    </div><!-- /.container -->

    <footer class="footer mt-5">
        <div class="footer-content py-4">
            <div class="container">
                <div class="row">
                    <div class="col-md-4 mb-3 mb-md-0">
                        <div class="footer-brand">
                            <img src="assets/img/logo.png" alt="Logo" class="footer-logo">
                            <h4 class="mt-3">Stop Shop İdarəetmə Sistemi</h4>
                            <p class="mb-0">Pavilion.az - Mağaza İdarəetmə Həlliniz</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3 mb-md-0">
                        <h5>Faydalı Linklər</h5>
                        <ul class="footer-links">
                            <li><a href="dashboard.php"><i class="fas fa-home me-2"></i>Ana Səhifə</a></li>
                            <li><a href="employees.php"><i class="fas fa-users me-2"></i>İşçilər</a></li>
                            <li><a href="reports.php"><i class="fas fa-chart-bar me-2"></i>Hesabatlar</a></li>
                            <li><a href="debts.php"><i class="fas fa-hand-holding-usd me-2"></i>Borclar</a></li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h5>Əlaqə</h5>
                        <ul class="footer-contact">
                            <li><i class="fas fa-phone me-2"></i> +994 12 345 67 89</li>
                            <li><i class="fas fa-envelope me-2"></i> info@pavilion.az</li>
                            <li><i class="fas fa-map-marker-alt me-2"></i> Bakı şəhəri, Stop Shop Ticarət Mərkəzi</li>
                        </ul>
                        <div class="social-icons mt-3">
                            <a href="#" class="social-icon"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="social-icon"><i class="fab fa-telegram"></i></a>
                            <a href="#" class="social-icon"><i class="fab fa-whatsapp"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="footer-bottom py-3">
            <div class="container text-center">
                <span>&copy; <?php echo date('Y'); ?> | Stop Shop İdarəetmə Sistemi. Bütün hüquqlar qorunur.</span>
                <div class="mt-2">
                    <span class="footer-version">Version 2.1.0</span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Əsas JavaScript -->
    <script src="assets/js/main.js"></script>
    
    <?php if (isset($additional_scripts) && !empty($additional_scripts)): ?>
    <!-- Əlavə JavaScript -->
    <?php foreach($additional_scripts as $script): ?>
    <script src="<?php echo $script; ?>"></script>
    <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($page_specific_script)): ?>
    <script>
        <?php echo $page_specific_script; ?>
    </script>
    <?php endif; ?>
    
    <!-- DataTables və digər JS elementlərinin ilkin konfiqurasiyası -->
    <script>
        $(document).ready(function() {
            // Auto hide alerts after 5 seconds
            setTimeout(function() {
                $('.alert-success, .alert-danger').fadeOut('slow');
            }, 5000);
            
            // Initialize DataTables where available
            <?php if (!isset($disable_auto_datatables) || !$disable_auto_datatables): ?>
            if ($.fn.DataTable && $('.datatable').length) {
                $('.datatable').DataTable({
                    "language": {
                        "url": "//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json"
                    },
                    "responsive": true,
                    "pageLength": 25
                });
            }
            <?php endif; ?>
            
            // Tooltips initialized
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
            
            // Animate on scroll
            const animateElements = document.querySelectorAll('.animate-on-scroll');
            if (animateElements.length) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('animate__animated', 'animate__fadeInUp');
                            observer.unobserve(entry.target);
                        }
                    });
                }, {threshold: 0.1});
                
                animateElements.forEach(element => {
                    observer.observe(element);
                });
            }
        });
    </script>

    <style>
        /* Footer styles */
        .footer {
            background: linear-gradient(45deg, #1f1c2c, #928dab);
            color: #fff;
            margin-top: 4rem;
        }
        
        .footer-content {
            position: relative;
        }
        
        .footer-brand h4 {
            font-weight: 600;
            font-size: 1.4rem;
            margin-bottom: 5px;
        }
        
        .footer-brand p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }
        
        .footer-logo {
            max-height: 60px;
        }
        
        .footer h5 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer h5:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
        }
        
        .footer-links, .footer-contact {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li, .footer-contact li {
            margin-bottom: 12px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .footer-links a, .footer-contact a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .footer-links a:hover {
            color: #fff;
            transform: translateX(5px);
        }
        
        .social-icons {
            display: flex;
            gap: 10px;
        }
        
        .social-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .social-icon:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
            color: white;
        }
        
        .footer-bottom {
            background-color: rgba(0, 0, 0, 0.2);
            font-size: 0.9rem;
        }
        
        .footer-version {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.5);
        }
        
        /* Scroll to top button */
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, #1f1c2c, #928dab);
            border-radius: 50%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            z-index: 999;
        }
        
        .scroll-top.active {
            opacity: 1;
            visibility: visible;
        }
        
        .scroll-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
        }
        
        /* Animation classes */
        .animate-on-scroll {
            opacity: 0;
        }
        
        @media (max-width: 767px) {
            .footer-content {
                text-align: center;
            }
            
            .footer h5:after {
                left: 50%;
                transform: translateX(-50%);
            }
            
            .social-icons {
                justify-content: center;
            }
        }
    </style>
    
    <!-- Scroll to top button -->
    <div class="scroll-top" id="scrollTop">
        <i class="fas fa-arrow-up"></i>
    </div>
    
    <script>
        // Scroll to top functionality
        document.addEventListener('DOMContentLoaded', function() {
            const scrollTop = document.getElementById('scrollTop');
            
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    scrollTop.classList.add('active');
                } else {
                    scrollTop.classList.remove('active');
                }
            });
            
            scrollTop.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>
