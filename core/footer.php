<?php
// core/footer.php
?>
    </div>
    
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
