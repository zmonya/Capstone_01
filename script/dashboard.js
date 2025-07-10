document.addEventListener('DOMContentLoaded', () => {
    setupSidebarToggle();
    setupDragAndDrop();
    setupFileDetailsForm();
    setupSendFileForm();
    setupPopupToggles();
    setupActivityLog();
    setupNotifications();
    setupFileSection();
    setupSelect2();
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
    setInterval(fetchAccessNotifications, 5000);
});

// Sidebar Toggle
function setupSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const topNav = document.querySelector('.top-nav');
    const mainContent = document.querySelector('.main-content');
    const toggleBtn = document.querySelector('.toggle-btn');
    const hamburgerMenu = document.querySelector('.hamburger-menu');
    const overlay = document.querySelector('.overlay') || createOverlay();

    if (sidebar && topNav && mainContent && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('minimized');
            topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
            mainContent.classList.toggle('resized', sidebar.classList.contains('minimized'));
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('active', !sidebar.classList.contains('minimized'));
                overlay.classList.toggle('show', sidebar.classList.contains('active'));
            }
        });

        if (hamburgerMenu) {
            hamburgerMenu.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                sidebar.classList.toggle('minimized', !sidebar.classList.contains('active'));
                overlay.classList.toggle('show', sidebar.classList.contains('active'));
            });
        }

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') &&
                !e.target.closest('.sidebar') && !e.target.closest('.hamburger-menu')) {
                sidebar.classList.remove('active');
                sidebar.classList.add('minimized');
                overlay.classList.remove('show');
            }
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            sidebar.classList.add('minimized');
            overlay.classList.remove('show');
        });
    } else {
        console.error('Sidebar toggle elements not found.');
    }
}

function createOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    document.body.appendChild(overlay);
    return overlay;
}

// Drag and Drop File Upload
function setupDragAndDrop() {
    const dragDropArea = document.querySelector('.drag-drop-area');
    const fileInput = document.querySelector('#fileInput');
    const progressBar = document.querySelector('.progress-bar .progress');

    if (dragDropArea && fileInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dragDropArea.addEventListener(eventName, preventDefaults, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dragDropArea.addEventListener(eventName, () => dragDropArea.classList.add('drag-over'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dragDropArea.addEventListener(eventName, () => dragDropArea.classList.remove('drag-over'), false);
        });

        dragDropArea.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            fileInput.files = files;
            handleFiles(files);
        });

        fileInput.addEventListener('change', () => handleFiles(fileInput.files));

        dragDropArea.addEventListener('click', () => fileInput.click());
    }
}

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function handleFiles(files) {
    if (files.length > 0) {
        const file = files[0];
        window.selectedFile = file;
        const progressBar = document.querySelector('.progress-bar .progress');
        let progress = 0;

        const simulateUpload = setInterval(() => {
            progress += 10;
            progressBar.style.width = `${progress}%`;
            if (progress >= 100) {
                clearInterval(simulateUpload);
                showPopup('fileDetailsPopup');
            }
        }, 200);
    }
}

// File Details Form
function setupFileDetailsForm() {
    const form = document.querySelector('#fileDetailsForm');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const documentType = form.querySelector('#documentType').value;
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            hidePopup('fileDetailsPopup');
            showPopup('hardcopyStoragePopup');
        });

        const hardcopyCheckbox = form.querySelector('#hardcopyCheckbox');
        const hardcopyOptions = form.querySelector('#hardcopyOptions');
        if (hardcopyCheckbox && hardcopyOptions) {
            hardcopyCheckbox.addEventListener('change', () => {
                hardcopyOptions.style.display = hardcopyCheckbox.checked ? 'block' : 'none';
                if (!hardcopyCheckbox.checked) {
                    document.querySelector('#storageSuggestion').style.display = 'none';
                } else if (document.querySelector('input[name="hardcopyOption"]:checked').value === 'new') {
                    fetchStorageSuggestion();
                }
            });
        }

        const hardcopyOptionsRadios = document.querySelectorAll('input[name="hardcopyOption"]');
        hardcopyOptionsRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.value === 'new') {
                    fetchStorageSuggestion();
                } else {
                    document.querySelector('#storageSuggestion').style.display = 'none';
                }
            });
        });

        const departmentSelect = form.querySelector('#departmentId');
        if (departmentSelect) {
            departmentSelect.addEventListener('change', () => {
                const departmentId = departmentSelect.value;
                loadSubDepartments(departmentId);
            });
        }

        const docTypeSelect = form.querySelector('#documentType');
        if (docTypeSelect) {
            docTypeSelect.addEventListener('change', () => {
                const docTypeName = docTypeSelect.value;
                const dynamicFields = document.querySelector('#dynamicFields');
                dynamicFields.innerHTML = '';
                if (docTypeName) {
                    jQuery.ajax({
                        url: 'get_document_type_field.php',
                        method: 'GET',
                        data: { document_type_name: docTypeName },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && data.fields.length > 0) {
                                data.fields.forEach(field => {
                                    const requiredAttr = field.is_required ? 'required' : '';
                                    let inputField = '';
                                    switch (field.field_type) {
                                        case 'text':
                                            inputField = `<input type="text" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                        case 'textarea':
                                            inputField = `<textarea id="${field.field_name}" name="${field.field_name}" ${requiredAttr}></textarea>`;
                                            break;
                                        case 'date':
                                            inputField = `<input type="date" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                    }
                                    dynamicFields.insertAdjacentHTML('beforeend', `
                                        <label for="${field.field_name}">${field.field_label}${field.is_required ? ' *' : ''}:</label>
                                        ${inputField}
                                    `);
                                });
                            } else {
                                dynamicFields.innerHTML = '<p>No additional fields required for this document type.</p>';
                            }
                        },
                        error: function() {
                            notyf.error('Failed to load document type fields.');
                        }
                    });
                }
            });
        }
    }
}

function loadSubDepartments(departmentId, selectedSubDeptId = null) {
    const subDeptSelect = document.querySelector('#subDepartmentId');
    subDeptSelect.innerHTML = '<option value="">No Sub-Department</option>';
    if (departmentId) {
        jQuery.ajax({
            url: 'get_sub_departments.php',
            method: 'GET',
            data: { department_id: departmentId },
            dataType: 'json',
            success: function(data) {
                data.forEach(subDept => {
                    const isSelected = subDept.id == selectedSubDeptId ? 'selected' : '';
                    subDeptSelect.insertAdjacentHTML('beforeend', `
                        <option value="${subDept.id}" ${isSelected}>${subDept.name}</option>
                    `);
                });
            },
            error: function() {
                notyf.error('Failed to load sub-departments.');
            }
        });
    }
}

function fetchStorageSuggestion() {
    const departmentId = document.querySelector('#departmentId').value;
    const subDepartmentId = document.querySelector('#subDepartmentId').value || null;
    const storageSuggestion = document.querySelector('#storageSuggestion');
    if (!departmentId) {
        storageSuggestion.innerHTML = '<p>No department selected.</p>';
        storageSuggestion.style.display = 'block';
        return;
    }
    jQuery.ajax({
        url: 'get_storage_suggestions.php',
        method: 'POST',
        data: { department_id: departmentId, sub_department_id: subDepartmentId },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                storageSuggestion.innerHTML = `<p>Suggested Location: ${data.suggestion}</p><span>Based on department/sub-department selection</span>`;
                storageSuggestion.style.display = 'block';
            } else {
                storageSuggestion.innerHTML = `<p>${data.suggestion || 'No suggestion available'}</p>`;
                storageSuggestion.style.display = 'block';
            }
        },
        error: function() {
            storageSuggestion.innerHTML = '<p>Failed to fetch suggestion.</p>';
            storageSuggestion.style.display = 'block';
        }
    });
}

// Send File Form
function setupSendFileForm() {
    const form = document.querySelector('#sendFileForm');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const recipients = jQuery('#recipientSelect').val();
            if (!recipients || recipients.length === 0) {
                notyf.error('Please select at least one recipient.');
                return;
            }
            const fileId = document.querySelector('.file-item.selected')?.dataset.fileId || form.closest('#sendFilePopup').dataset.selectedFileId;
            if (!fileId) {
                notyf.error('No file selected to send.');
                return;
            }
            jQuery.ajax({
                url: 'send_file_handler.php',
                method: 'POST',
                data: { file_id: fileId, recipients: recipients },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showNotification(response.message, 'success');
                        logActivity(response.message);
                        hidePopup('sendFilePopup');
                        document.querySelectorAll('.file-item').forEach(item => item.classList.remove('selected'));
                        form.closest('#sendFilePopup').removeAttribute('data-selected-file-id');
                    } else {
                        notyf.error(response.message || 'Error sending file.');
                    }
                },
                error: function() {
                    notyf.error('Error sending file.');
                }
            });
        });
    }
}

// Popup Toggles
function setupPopupToggles() {
    document.querySelectorAll('.exit-button').forEach(button => {
        button.addEventListener('click', () => {
            const popup = button.closest('.popup-questionnaire, .popup-file-selection');
            if (popup) {
                hidePopup(popup.id);
                if (popup.id === 'sendFilePopup') {
                    document.querySelectorAll('.file-item').forEach(item => item.classList.remove('selected'));
                    popup.removeAttribute('data-selected-file-id');
                } else if (popup.id === 'fileDetailsPopup') {
                    window.selectedFile = null;
                } else if (popup.id === 'hardcopyStoragePopup') {
                    document.querySelector('#storageSuggestion').innerHTML = '';
                }
            }
        });
    });

    document.querySelectorAll('.btn-back').forEach(button => {
        button.addEventListener('click', () => {
            const popup = button.closest('.popup-questionnaire, .popup-file-selection');
            if (popup.id === 'sendFilePopup') {
                hidePopup('sendFilePopup');
                showPopup('fileSelectionPopup');
            } else if (popup.id === 'hardcopyStoragePopup') {
                hidePopup('hardcopyStoragePopup');
                showPopup('fileDetailsPopup');
            } else if (popup.id === 'linkHardcopyPopup') {
                hidePopup('linkHardcopyPopup');
                showPopup('hardcopyStoragePopup');
            }
        });
    });

    document.querySelectorAll('.btn-next').forEach(button => {
        button.addEventListener('click', () => {
            const popup = button.closest('.popup-questionnaire, .popup-file-selection');
            if (popup.id === 'fileDetailsPopup') {
                proceedToHardcopy();
            } else if (popup.id === 'hardcopyStoragePopup') {
                const hardcopyAvailable = document.querySelector('#hardcopyCheckbox').checked;
                if (hardcopyAvailable && document.querySelector('input[name="hardcopyOption"]:checked').value === 'link') {
                    hidePopup('hardcopyStoragePopup');
                    showPopup('linkHardcopyPopup');
                    fetchHardcopyFiles();
                } else {
                    uploadFile();
                }
            } else if (popup.id === 'linkHardcopyPopup') {
                linkHardcopy();
            }
        });
    });

    const selectDocumentButton = document.querySelector('#selectDocumentButton');
    if (selectDocumentButton) {
        selectDocumentButton.addEventListener('click', () => showPopup('fileSelectionPopup'));
    }

    const uploadFileButton = document.querySelector('#uploadFileButton');
    if (uploadFileButton) {
        uploadFileButton.addEventListener('click', () => {
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.style.display = 'none';
            document.body.appendChild(fileInput);
            fileInput.click();
            fileInput.addEventListener('change', () => {
                if (fileInput.files[0]) {
                    window.selectedFile = fileInput.files[0];
                    showPopup('fileDetailsPopup');
                }
                fileInput.remove();
            });
        });
    }
}

function showPopup(popupId) {
    const popup = document.getElementById(popupId);
    const backdrop = document.querySelector('.popup-backdrop') || createBackdrop();
    if (popup && backdrop) {
        popup.style.display = 'block';
        popup.classList.add('show');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
        popup.focus(); // Improve accessibility
    }
}

function hidePopup(popupId) {
    const popup = document.getElementById(popupId);
    const backdrop = document.querySelector('.popup-backdrop');
    if (popup && backdrop) {
        popup.classList.remove('show');
        popup.style.display = 'none';
        backdrop.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

function createBackdrop() {
    const backdrop = document.createElement('div');
    backdrop.className = 'popup-backdrop';
    document.body.appendChild(backdrop);
    return backdrop;
}

function proceedToHardcopy() {
    const documentType = document.querySelector('#documentType').value;
    if (!documentType) {
        notyf.error('Please select a document type.');
        return;
    }
    const departmentId = document.querySelector('#departmentId').value;
    hidePopup('fileDetailsPopup');
    if (departmentId) {
        showPopup('hardcopyStoragePopup');
        if (document.querySelector('#hardcopyCheckbox').checked &&
            document.querySelector('input[name="hardcopyOption"]:checked').value === 'new') {
            fetchStorageSuggestion();
        }
    } else {
        uploadFile();
    }
}

function fetchHardcopyFiles() {
    jQuery.ajax({
        url: 'fetch_hardcopy_files.php',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            const hardcopyList = document.querySelector('#hardcopyList');
            hardcopyList.innerHTML = '';
            data.forEach(file => {
                hardcopyList.insertAdjacentHTML('beforeend', `
                    <div class="file-item" data-file-id="${file.id}">
                        <input type="radio" name="hardcopyFile" value="${file.id}" id="hardcopy-${file.id}">
                        <label for="hardcopy-${file.id}">${file.file_name}</label>
                    </div>
                `);
            });
            hardcopyList.querySelectorAll('input').forEach(input => {
                input.addEventListener('change', () => {
                    window.selectedHardcopyId = input.value;
                    document.querySelector('#linkHardcopyButton').disabled = false;
                });
            });
        },
        error: function() {
            notyf.error('Failed to fetch hardcopy files.');
        }
    });
}

function linkHardcopy() {
    if (!window.selectedHardcopyId) {
        notyf.error('Please select a hardcopy to link.');
        return;
    }
    uploadFile();
}

function uploadFile() {
    const documentType = document.querySelector('#documentType').value;
    const departmentId = document.querySelector('#departmentId').value || null;
    const subDepartmentId = document.querySelector('#subDepartmentId').value || null;
    const hardcopyAvailable = document.querySelector('#hardcopyCheckbox').checked;
    const hardcopyOption = hardcopyAvailable ? document.querySelector('input[name="hardcopyOption"]:checked')?.value : null;

    if (!window.selectedFile) {
        notyf.error('No file selected.');
        return;
    }

    const formData = new FormData();
    formData.append('file', window.selectedFile);
    formData.append('document_type', documentType);
    if (departmentId) formData.append('department_id', departmentId);
    if (subDepartmentId) formData.append('sub_department_id', subDepartmentId);
    formData.append('hard_copy_available', hardcopyAvailable ? 1 : 0);
    if (hardcopyAvailable && hardcopyOption === 'link' && window.selectedHardcopyId) {
        formData.append('link_hardcopy_id', window.selectedHardcopyId);
    } else if (hardcopyAvailable && hardcopyOption === 'new') {
        formData.append('new_storage', 1);
        if (window.storageMetadata) {
            formData.append('storage_metadata', JSON.stringify(window.storageMetadata));
        }
    }

    document.querySelectorAll('#fileDetailsForm input, #fileDetailsForm textarea, #fileDetailsForm select').forEach(element => {
        const name = element.name;
        const value = element.value;
        if (name && value && !['department_id', 'document_type', 'sub_department_id'].includes(name)) {
            formData.append(name, value);
        }
    });

    jQuery.ajax({
        url: 'upload_handler.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(data) {
            const response = typeof data === 'string' ? JSON.parse(data) : data;
            if (response.success) {
                showNotification(response.message, 'success');
                logActivity(response.message);
                hidePopup('hardcopyStoragePopup');
                hidePopup('linkHardcopyPopup');
                window.selectedFile = null;
                window.selectedHardcopyId = null;
                window.storageMetadata = null;
                window.location.href = response.redirect || 'my-folder.php';
            } else {
                notyf.error(response.message || 'Failed to upload file.');
            }
        },
        error: function() {
            notyf.error('An error occurred while uploading the file.');
        }
    });
}

// Activity Log
function setupActivityLog() {
    const activityLogIcon = document.querySelector('.activity-log-icon');
    const activityLog = document.querySelector('.activity-log');
    if (activityLogIcon && activityLog) {
        activityLogIcon.addEventListener('click', (e) => {
            e.stopPropagation();
            activityLog.classList.toggle('show');
            if (activityLog.classList.contains('show')) {
                activityLog.focus(); // Improve accessibility
            }
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.activity-log') && !e.target.closest('.activity-log-icon')) {
                activityLog.classList.remove('show');
            }
        });
        // Keyboard accessibility
        activityLog.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                activityLog.classList.remove('show');
                activityLogIcon.focus();
            }
        });
    }
}

function logActivity(message) {
    const logEntries = document.querySelector('.log-entries');
    if (logEntries) {
        const entry = document.createElement('div');
        entry.classList.add('log-entry');
        entry.innerHTML = `
            <i class="fas fa-info-circle"></i>
            <p>${message}</p>
            <span>${new Date().toLocaleTimeString()}</span>
        `;
        logEntries.prepend(entry);
    }
}

// Notifications
function setupNotifications() {
    const notificationItems = document.querySelectorAll('.notification-item');
    notificationItems.forEach(item => {
        item.addEventListener('click', () => {
            const type = item.dataset.type;
            const status = item.dataset.status;
            const fileId = item.dataset.fileId;
            const notificationId = item.dataset.notificationId;
            const message = item.dataset.message;

            if (status !== 'pending') {
                const alreadyProcessedPopup = document.querySelector('#alreadyProcessedPopup');
                alreadyProcessedPopup.querySelector('#alreadyProcessedMessage').textContent = 'This request has already been processed.';
                showPopup('alreadyProcessedPopup');
                return;
            }

            if (type === 'received' || type === 'access_request') {
                const fileAcceptancePopup = document.querySelector('#fileAcceptancePopup');
                fileAcceptancePopup.querySelector('#fileAcceptanceTitle').textContent = `Review ${type === 'received' ? 'Received File' : 'Access Request'}`;
                fileAcceptancePopup.querySelector('#fileAcceptanceMessage').textContent = message;
                fileAcceptancePopup.dataset.notificationId = notificationId;
                fileAcceptancePopup.dataset.fileId = fileId;
                showPopup('fileAcceptancePopup');
                showFilePreview(fileId);
            }
        });
    });

    const acceptFileButton = document.querySelector('#acceptFileButton');
    const denyFileButton = document.querySelector('#denyFileButton');

    if (acceptFileButton) {
        acceptFileButton.addEventListener('click', () => {
            const notificationId = document.querySelector('#fileAcceptancePopup').dataset.notificationId;
            const fileId = document.querySelector('#fileAcceptancePopup').dataset.fileId;
            handleFileAction(notificationId, fileId, 'accept');
        });
    }

    if (denyFileButton) {
        denyFileButton.addEventListener('click', () => {
            const notificationId = document.querySelector('#fileAcceptancePopup').dataset.notificationId;
            const fileId = document.querySelector('#fileAcceptancePopup').dataset.fileId;
            handleFileAction(notificationId, fileId, 'deny');
        });
    }
}

function showFilePreview(fileId) {
    jQuery.ajax({
        url: 'get_file_preview.php',
        method: 'GET',
        data: { file_id: fileId },
        success: function(data) {
            document.querySelector('#filePreview').innerHTML = data;
        },
        error: function() {
            document.querySelector('#filePreview').innerHTML = '<p>Unable to load preview.</p>';
        }
    });
}

function handleFileAction(notificationId, fileId, action) {
    jQuery.ajax({
        url: 'handle_file_acceptance.php',
        method: 'POST',
        data: { notification_id: notificationId, file_id: fileId, action: action },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification(response.message, 'success');
                hidePopup('fileAcceptancePopup');
                const notificationItem = document.querySelector(`.notification-item[data-notification-id="${notificationId}"]`);
                notificationItem.classList.remove('pending-access', 'received-pending');
                notificationItem.classList.add('processed-access');
                notificationItem.querySelector('p').textContent = `${response.message} (Processed)`;
                notificationItem.removeEventListener('click', () => {});
                fetchNotifications();
            } else {
                notyf.error(response.message);
            }
        },
        error: function() {
            notyf.error('Error processing file action.');
        }
    });
}

function fetchNotifications() {
    jQuery.ajax({
        url: 'fetch_notifications.php',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            const notificationContainer = document.querySelector('.notification-log .log-entries');
            if (Array.isArray(data) && data.length > 0) {
                const currentNotifications = Array.from(notificationContainer.querySelectorAll('.notification-item')).map(item => item.dataset.notificationId);
                const newNotifications = data.map(n => n.id);

                if (JSON.stringify(currentNotifications) !== JSON.stringify(newNotifications)) {
                    notificationContainer.innerHTML = '';
                    data.forEach(notification => {
                        const notificationClass = notification.type === 'access_request' && notification.status === 'pending' ?
                            'pending-access' :
                            (notification.type === 'received' && notification.status === 'pending' ? 'received-pending' : 'processed-access');
                        notificationContainer.insertAdjacentHTML('beforeend', `
                            <div class="log-entry notification-item ${notificationClass}"
                                data-notification-id="${notification.id}"
                                data-file-id="${notification.file_id}"
                                data-message="${notification.message}"
                                data-type="${notification.type}"
                                data-status="${notification.status}">
                                <i class="fas fa-bell"></i>
                                <p>${notification.message}</p>
                                <span>${new Date(notification.timestamp).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                            </div>
                        `);
                    });
                    setupNotifications();
                }
            } else if (Array.isArray(data) && data.length === 0 && notificationContainer.querySelectorAll('.notification-item').length === 0) {
                notificationContainer.innerHTML = '<div class="log-entry"><p>No new notifications.</p></div>';
            }
        },
        error: function() {
            notyf.error('Failed to fetch notifications.');
        }
    });
}

function fetchAccessNotifications() {
    // Placeholder for fetching access notifications
}

// File Section
function setupFileSection() {
    const fileSection = document.querySelector('.file-section');
    if (fileSection) {
        const sortSelect = document.querySelector('#sortSelect');
        const filterSearch = document.querySelector('#fileSearch');
        const filterType = document.querySelector('#documentTypeFilter');
        const hardCopyFilter = document.querySelector('#hardCopyFilter');

        if (sortSelect) {
            sortSelect.addEventListener('change', () => sortFiles(sortSelect.value));
        }
        if (filterSearch) {
            filterSearch.addEventListener('input', filterFiles);
        }
        if (filterType) {
            filterType.addEventListener('change', filterFiles);
        }
        if (hardCopyFilter) {
            hardCopyFilter.addEventListener('change', filterFiles);
        }

        const viewToggles = document.querySelectorAll('.view-toggle button');
        const fileListContainer = document.querySelector('.masonry-grid');
        viewToggles.forEach(button => {
            button.addEventListener('click', () => {
                viewToggles.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                if (button.dataset.view === 'list') {
                    fileListContainer.classList.add('list-view');
                    fileListContainer.classList.remove('masonry-grid');
                } else {
                    fileListContainer.classList.remove('list-view');
                    fileListContainer.classList.add('masonry-grid');
                }
            });
        });

        document.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (!e.target.closest('.file-actions')) {
                    document.querySelectorAll('.file-item').forEach(i => i.classList.remove('selected'));
                    item.classList.add('selected');
                    showPopup('fileSelectionPopup');
                }
            });

            item.addEventListener('mouseenter', () => {
                const fileId = item.dataset.fileId;
                showFilePreviewTooltip(fileId, item);
            });
            item.addEventListener('mouseleave', () => {
                const tooltip = item.querySelector('.file-tooltip');
                if (tooltip) tooltip.remove();
            });
        });

        document.querySelectorAll('.action-send').forEach(button => {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                const fileItem = button.closest('.file-item');
                document.querySelectorAll('.file-item').forEach(i => i.classList.remove('selected'));
                fileItem.classList.add('selected');
                showPopup('sendFilePopup');
            });
        });

        document.querySelectorAll('.action-view').forEach(button => {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                const fileId = button.closest('.file-item').dataset.fileId;
                showFilePreview(fileId);
            });
        });
    }
}

function showFilePreviewTooltip(fileId, item) {
    jQuery.ajax({
        url: 'get_file_preview.php',
        method: 'GET',
        data: { file_id: fileId },
        success: function(data) {
            const tooltip = document.createElement('div');
            tooltip.className = 'file-tooltip';
            tooltip.innerHTML = data;
            item.appendChild(tooltip);
            tooltip.style.position = 'absolute';
            tooltip.style.top = '100%';
            tooltip.style.left = '50%';
            tooltip.style.transform = 'translateX(-50%)';
            tooltip.style.zIndex = '1000';
        },
        error: function() {
            // Silent fail for tooltip
        }
    });
}

function sortFiles(criteria) {
    const fileListContainer = document.querySelector('.masonry-grid');
    const fileItems = Array.from(fileListContainer.querySelectorAll('.file-item'));

    fileItems.sort((a, b) => {
        if (criteria === 'name') {
            return a.dataset.fileName.localeCompare(b.dataset.fileName);
        } else if (criteria === 'date') {
            return new Date(b.dataset.uploadDate) - new Date(a.dataset.uploadDate);
        } else if (criteria === 'type') {
            return a.dataset.documentType.localeCompare(b.dataset.documentType);
        }
        return 0;
    });

    fileListContainer.innerHTML = '';
    fileItems.forEach(item => fileListContainer.appendChild(item));
}

function filterFiles() {
    const searchTerm = document.querySelector('#fileSearch').value.toLowerCase();
    const typeFilter = document.querySelector('#documentTypeFilter').value.toLowerCase();
    const hardCopyFilter = document.querySelector('#hardCopyFilter').checked;
    document.querySelectorAll('.file-item').forEach(item => {
        const fileName = item.dataset.fileName.toLowerCase();
        const docType = item.dataset.documentType.toLowerCase();
        const hasHardCopy = item.dataset.hardCopy === 'yes';
        const matchesSearch = fileName.includes(searchTerm);
        const matchesType = typeFilter === '' || docType === typeFilter;
        const matchesHardCopy = !hardCopyFilter || hasHardCopy;
        item.style.display = matchesSearch && matchesType && matchesHardCopy ? 'block' : 'none';
    });
}

// Select2 Initialization
function setupSelect2() {
    if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
        jQuery('#recipientSelect').select2({
            placeholder: 'Select recipients',
            allowClear: true,
            width: '100%'
        });
        jQuery('#documentTypeFilter').select2({
            placeholder: 'Filter by type',
            allowClear: true,
            width: '100%'
        });
    } else {
        console.warn('Select2 library not loaded.');
    }
}