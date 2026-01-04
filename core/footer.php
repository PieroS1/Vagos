<?php
// core/footer.php
?>
    </div> <!-- Cierre del container abierto en header -->

    <!-- 🌙 Footer limpio -->
    <footer class="bg-light text-center text-muted py-3 mt-4 border-top">
        <small>
            Sistema IoT — <?= date('Y') ?> |
            Monitoreo y Control en Tiempo Real
        </small>
    </footer>

    <!-- Bootstrap JS (necesario para navbar, toggler, etc.) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- 🔧 Tus scripts originales -->
    <script>
        // Animaciones básicas
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement.classList.remove('focused');
                    }
                });
            });
        });

        // Confirmación de acciones
        function confirmAction(message) {
            return confirm(message || '¿Está seguro de realizar esta acción?');
        }
    </script>

</body>
</html>
