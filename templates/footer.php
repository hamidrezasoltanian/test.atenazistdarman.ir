<div class="print-footer">© 2026 سامانه اتوماسیون اداری آتنا زیست درمان. نسخه 1.0.0</div>

    <script src="<?php echo $basePath; ?>assets/js/dashboard.js"></script>
    <script>
        function toggleProfileMenu(e) { 
            if(e) e.stopPropagation(); 
            document.getElementById('profileDropdown').classList.toggle('active'); 
        }
        window.addEventListener('click', function(e) {
            if (!e.target.closest('.profile-container')) {
                const ds = document.getElementsByClassName("dropdown-menu");
                for (let i=0; i<ds.length; i++) if (ds[i].classList.contains('active')) ds[i].classList.remove('active');
            }
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.querySelector('.menu-btn');
                if (sidebar && !sidebar.contains(e.target) && menuBtn && !menuBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            if ("Notification" in window && Notification.permission !== "granted" && Notification.permission !== "denied") {
                Notification.requestPermission();
            }
        });

        setInterval(() => {
            if ("Notification" in window && Notification.permission === "granted") {
                // آدرس‌دهی داینامیک API با استفاده از متغیر بیس‌پث
                fetch('<?php echo isset($basePath) ? $basePath : ""; ?>api/check_notifications.php', { cache: "no-store" })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.has_new) {
                        data.items.forEach(item => {
                            const notif = new Notification(item.title, {
                                body: item.content,
                                icon: '<?php echo isset($basePath) ? $basePath : ""; ?>assets/images/logo.png' 
                            });

                            notif.onclick = function() {
                                window.focus();
                                if(item.link) {
                                    window.location.href = '<?php echo isset($basePath) ? $basePath : ""; ?>' + item.link;
                                }
                            };
                        });
                    }
                })
                .catch(err => console.error("Error checking notifications:", err));
            }
        }, 30000); // بررسی هر ۳۰ ثانیه
    </script>
</body>
</html>