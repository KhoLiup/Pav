/**
 * main.js
 * Bütün səhifələrdə istifadə ediləcək ümumi JavaScript funksiyaları
 */

/**
 * Flash mesajlarını avtomatik bağlayır və animasiya əlavə edir
 */
function initFlashMessages() {
    // Add animation to alerts
    $('.alert').addClass('animate__animated animate__fadeIn');
    
    // Auto close alerts after timeout
    setTimeout(function() {
        $('.alert').addClass('animate__fadeOut');
        setTimeout(function() {
            $('.alert').remove();
        }, 500);
    }, 5000);
    
    // Add close button functionality
    $('.alert .close').on('click', function() {
        $(this).closest('.alert').addClass('animate__fadeOut');
        setTimeout(function(alert) {
            $(alert).remove();
        }, 500, this);
    });
}

/**
 * DataTables cədvəllərini ilkin konfiqurasiya edir
 */
function initDataTables() {
    if ($.fn.DataTable && $('.datatable').length) {
        $('.datatable').each(function() {
            let pageLength = $(this).data('page-length') || 25;
            let searching = $(this).data('searching') !== false;
            let ordering = $(this).data('ordering') !== false;
            
            $(this).DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json"
                },
                "responsive": true,
                "pageLength": pageLength,
                "searching": searching,
                "ordering": ordering,
                "stateSave": true,
                "dom": '<"top"lf>rt<"bottom"ip>',
                "initComplete": function() {
                    $('.dataTables_wrapper .dataTables_filter input').addClass('form-control');
                    $('.dataTables_wrapper .dataTables_length select').addClass('form-select');
                }
            });
        });
    }
}

/**
 * Axtarış field-in işləməsi üçün
 */
function initSearchFilter() {
    $("#searchInput").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#dataTable tbody tr, .searchable-item").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
}

/**
 * Form veriləri üçün ümumi doğrulama
 * @param {HTMLFormElement} form - Form elementi
 * @returns {boolean} - Formun doğru olub-olmadığı
 */
function validateForm(form) {
    let isValid = true;
    let firstInvalidElement = null;
    
    // Clear previous validation messages
    $(form).find('.invalid-feedback').remove();
    
    // Required field yoxlanışı
    $(form).find('[required]').each(function() {
        if ($(this).val() === '') {
            isValid = false;
            if (!firstInvalidElement) firstInvalidElement = this;
            
            $(this).addClass('is-invalid');
            let fieldName = $(this).attr('data-name') || $(this).closest('.form-group').find('label').text() || 'Bu sahə';
            $(this).after(`<div class="invalid-feedback">${fieldName} tələb olunur</div>`);
        } else {
            $(this).removeClass('is-invalid').addClass('is-valid');
        }
    });
    
    // Email formatı yoxlanışı
    $(form).find('input[type="email"]').each(function() {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if ($(this).val() !== '' && !emailRegex.test($(this).val())) {
            isValid = false;
            if (!firstInvalidElement) firstInvalidElement = this;
            
            $(this).addClass('is-invalid');
            $(this).after('<div class="invalid-feedback">Düzgün email formatı daxil edin</div>');
        } else if ($(this).val() !== '') {
            $(this).removeClass('is-invalid').addClass('is-valid');
        }
    });
    
    // Telefon nömrəsi formatı yoxlanışı
    $(form).find('input[data-type="phone"]').each(function() {
        const phoneRegex = /^994[0-9]{9}$/;
        if ($(this).val() !== '' && !phoneRegex.test($(this).val())) {
            isValid = false;
            if (!firstInvalidElement) firstInvalidElement = this;
            
            $(this).addClass('is-invalid');
            $(this).after('<div class="invalid-feedback">Düzgün telefon formatı daxil edin (994XXXXXXXXX)</div>');
        } else if ($(this).val() !== '') {
            $(this).removeClass('is-invalid').addClass('is-valid');
        }
    });
    
    // Focus to first invalid element
    if (firstInvalidElement) {
        $(firstInvalidElement).focus();
    }
    
    return isValid;
}

/**
 * Tarixi formatla
 * @param {string} dateString - Tarix sətri (YYYY-MM-DD)
 * @param {string} format - Formatı (default: 'dd.mm.yyyy')
 * @returns {string} - Formatlanmış tarix
 */
function formatDate(dateString, format = 'dd.mm.yyyy') {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';
    
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const year = date.getFullYear();
    
    switch (format) {
        case 'dd.mm.yyyy':
            return `${day}.${month}.${year}`;
        case 'mm.dd.yyyy':
            return `${month}.${day}.${year}`;
        case 'yyyy-mm-dd':
            return `${year}-${month}-${day}`;
        case 'dd/mm/yyyy':
            return `${day}/${month}/${year}`;
        default:
            return `${day}.${month}.${year}`;
    }
}

/**
 * Rəqəmi formatla
 * @param {number} number - Rəqəm
 * @param {number} decimals - Onluq hissəsindəki rəqəmlərin sayı
 * @returns {string} - Formatlanmış rəqəm
 */
function formatNumber(number, decimals = 2) {
    if (isNaN(number)) return '0.00';
    return parseFloat(number).toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Modal pəncərə açılanda formu sıfırla
 */
function resetFormOnModalShow() {
    $('.modal').on('show.bs.modal', function() {
        const form = $(this).find('form');
        if (form.length) {
            form[0].reset();
            form.find('.is-invalid').removeClass('is-invalid');
            form.find('.is-valid').removeClass('is-valid');
            form.find('.invalid-feedback').remove();
        }
    });
}

/**
 * Form submit zamanı düymələri deaktiv et və loading ikonkası göstər
 */
function handleFormSubmit() {
    $('form').on('submit', function() {
        const submitBtn = $(this).find('button[type="submit"]');
        if (submitBtn.length) {
            const originalText = submitBtn.html();
            submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>İcra edilir...');
            
            // Reset button after timeout (if form submission gets stuck)
            setTimeout(function() {
                if (submitBtn.prop('disabled')) {
                    submitBtn.prop('disabled', false).html(originalText);
                }
            }, 10000);
        }
    });
}

/**
 * Cədvəl sıralama üçün
 */
function initTableSorting() {
    $('th[data-sort]').on('click', function() {
        const column = $(this).data('sort');
        const table = $(this).closest('table');
        const rows = table.find('tbody tr').toArray();
        
        const isAsc = $(this).hasClass('sort-asc');
        $(this).toggleClass('sort-asc', !isAsc).toggleClass('sort-desc', isAsc);
        
        // Remove sort classes from other columns
        $(this).siblings().removeClass('sort-asc sort-desc');
        
        // Sort the rows
        rows.sort(function(a, b) {
            const aValue = $(a).find(`td[data-col="${column}"]`).text().trim();
            const bValue = $(b).find(`td[data-col="${column}"]`).text().trim();
            
            if (isNaN(aValue) || isNaN(bValue)) {
                return isAsc ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
            } else {
                return isAsc ? parseFloat(aValue) - parseFloat(bValue) : parseFloat(bValue) - parseFloat(aValue);
            }
        });
        
        // Re-append the sorted rows
        $.each(rows, function(index, row) {
            table.find('tbody').append(row);
        });
    });
}

/**
 * Checkbox seçim funksionalı
 */
function initCheckboxHandlers() {
    // Select all checkbox
    $('#selectAll').on('change', function() {
        const isChecked = $(this).prop('checked');
        $('.item-checkbox').prop('checked', isChecked);
        updateSelectedCount();
    });
    
    // Individual checkboxes
    $(document).on('change', '.item-checkbox', function() {
        const allChecked = $('.item-checkbox:checked').length === $('.item-checkbox').length;
        $('#selectAll').prop('checked', allChecked);
        updateSelectedCount();
    });
    
    // Bulk action button
    $('.bulk-action-btn').on('click', function() {
        const action = $(this).data('action');
        const selectedIds = [];
        
        $('.item-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            showAlert('warning', 'Zəhmət olmasa, ən azı bir element seçin');
            return;
        }
        
        // Confirm action
        if (action === 'delete') {
            if (confirm(`${selectedIds.length} elementi silmək istədiyinizə əminsiniz?`)) {
                processBulkAction(action, selectedIds);
            }
        } else {
            processBulkAction(action, selectedIds);
        }
    });
}

/**
 * Seçilmiş elementlərin sayını yeniləyir
 */
function updateSelectedCount() {
    const count = $('.item-checkbox:checked').length;
    $('#selectedCount').text(count);
    
    if (count > 0) {
        $('.bulk-actions').removeClass('d-none');
    } else {
        $('.bulk-actions').addClass('d-none');
    }
}

/**
 * Alert mesajı göstərmək
 * @param {string} type - mesaj tipi: success, warning, danger, info
 * @param {string} message - göstəriləcək mesaj
 * @param {number} duration - göstərilmə müddəti (ms), 0 = sonsuz
 */
function showAlert(type, message, duration = 5000) {
    // Map type to bootstrap alert class and icon
    const alertClass = {
        success: 'alert-success',
        warning: 'alert-warning',
        danger: 'alert-danger',
        info: 'alert-info'
    }[type] || 'alert-info';
    
    const icon = {
        success: 'fa-check-circle',
        warning: 'fa-exclamation-triangle',
        danger: 'fa-times-circle',
        info: 'fa-info-circle'
    }[type] || 'fa-info-circle';
    
    // Create alert element
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
            <i class="fas ${icon} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    
    // Append to alerts container or create one
    let alertsContainer = $('#alertsContainer');
    if (alertsContainer.length === 0) {
        $('body').append('<div id="alertsContainer" class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1050;"></div>');
        alertsContainer = $('#alertsContainer');
    }
    
    // Add the alert
    const alert = $(alertHtml).appendTo(alertsContainer);
    
    // Auto close after duration (if not 0)
    if (duration > 0) {
        setTimeout(function() {
            alert.removeClass('animate__fadeInDown').addClass('animate__fadeOutUp');
            setTimeout(function() {
                alert.remove();
            }, 500);
        }, duration);
    }
}

/**
 * Tooltip və popover funksiyalarını aktivləşdirmək
 */
function initTooltipsAndPopovers() {
    $('[data-bs-toggle="tooltip"]').tooltip();
    $('[data-bs-toggle="popover"]').popover();
}

/**
 * Animasiyalı sayım effekti
 */
function initCountAnimation() {
    $('.count-up').each(function() {
        const $this = $(this);
        const countTo = parseFloat($this.text().replace(/,/g, ''));
        
        if (isNaN(countTo)) return;
        
        $this.text('0');
        
        $({ countNum: 0 }).animate({
            countNum: countTo
        }, {
            duration: 1500,
            easing: 'swing',
            step: function() {
                const formattedNumber = Math.floor(this.countNum).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                $this.text(formattedNumber);
            },
            complete: function() {
                const formattedNumber = this.countNum.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                $this.text(formattedNumber);
            }
        });
    });
}

/**
 * Səhifə yüklənəndə ilkin funksiyaları işə salmaq
 */
$(document).ready(function() {
    // Initialize all components
    initFlashMessages();
    initDataTables();
    initSearchFilter();
    resetFormOnModalShow();
    handleFormSubmit();
    initTableSorting();
    initCheckboxHandlers();
    initTooltipsAndPopovers();
    
    // Animate on scroll effect for dashboard cards
    $('.stat-card, .card').addClass('animate-on-scroll');
    $('.animate-on-scroll').each(function(i) {
        const elem = $(this);
        setTimeout(function() {
            elem.addClass('animate__animated animate__fadeInUp');
        }, i * 100);
    });
    
    // Initialize count animation after a delay
    setTimeout(function() {
        initCountAnimation();
    }, 500);
    
    // Print button functionality
    $('.print-btn').on('click', function() {
        window.print();
    });
    
    // Back button functionality
    $('.back-btn').on('click', function() {
        window.history.back();
    });
    
    // Listen for Bootstrap modal events to reset form validation
    $('.modal').on('hidden.bs.modal', function() {
        $(this).find('form').find('.is-invalid, .is-valid').removeClass('is-invalid is-valid');
    });
    
    // Add animation when new elements are added to tables
    $(document).on('DOMNodeInserted', 'table tbody tr', function() {
        $(this).addClass('animate__animated animate__fadeIn');
    });
});