jQuery(document).ready(function($) {
    var formChanged = false;
    var allHostsToggled = true;
    var allPathsToggled = true;
    var $table = $('#outbound-calls-table');
    var $tableBody = $table.find('tbody');
    var $searchInput = $('#outbound-search');
    var $listeningToggle = $('#listening-toggle');
    var $listeningStatus = $('#listening-status');
    var $submitButton = $('#submit');
    var currentOrderBy = 'last_call';
    var currentOrder = 'DESC';
    var searchTimeout;
    var originalState = { hosts: {}, paths: {}, rewrites: {} };

    function debug(message) {
        console.log("Debug: " + message);
    }

    function handleRewriteUrlChange($input) {
        var currentValue = $input.val().trim();
        var originalValue = originalState.rewrites[$input.attr('name')] || '';
        
        debug("Rewrite URL input change detected:");
        debug("Input name: " + $input.attr('name'));
        debug("Current value: " + currentValue);
        debug("Original value: " + originalValue);
        
        if (currentValue !== originalValue) {
            debug("Rewrite URL value changed. Activating save button.");
            formChanged = true;
            updateSaveButton();
        } else {
            debug("Rewrite URL value matches original.");
        }
    }

    function updateSaveButton() {
        var wasDisabled = $submitButton.prop('disabled');
        $submitButton.prop('disabled', !formChanged);
        debug("updateSaveButton called. formChanged: " + formChanged + ", button disabled: " + $submitButton.prop('disabled'));
        if (wasDisabled !== !formChanged) {
            debug("Save button " + (formChanged ? "enabled" : "disabled"));
        }
    }

    function setFormChanged() {
        debug("setFormChanged called. Activating save button.");
        formChanged = true;
        updateSaveButton();
    }

    // Event listeners for form changes
    $('input[name="blocked_hosts[]"], input[name="blocked_paths[]"]').on('change', function() {
        debug("Change detected on: " + $(this).attr('name'));
        checkForChanges();
        updateRewriteUrlState($(this).closest('tr'));
    });

    // Event listener for rewrite URL inputs
    $(document).on('input', 'input[name^="rewrite_urls["]', function() {
        handleRewriteUrlChange($(this));
    });
        
    function loadRecords(search = '') {
        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: search ? 'search_records' : 'load_all_records',
                security: outboundCallLogger.nonce,
                search: search,
                orderby: currentOrderBy,
                order: currentOrder
            },
            success: function(response) {
                if (response.success) {
                    $tableBody.html(response.data.html);
                    updateSortingIndicators(currentOrderBy, currentOrder);
                    initializeRowListeners();
                    initializeCopyButtons();
                } else {
                    console.error('Failed to update table');
                }
            },
            error: function() {
                console.error('AJAX error when loading records');
            }
        });
    }

    $('#run-cleanup').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Running cleanup...');
        
        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: 'manual_cleanup',
                security: outboundCallLogger.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayAdminNotice('Cleanup completed successfully', 'success');
                    // Reload the table to show updated data
                    loadRecords();
                } else {
                    displayAdminNotice('Error during cleanup: ' + response.data.message, 'error');
                }
            },
            error: function() {
                displayAdminNotice('An error occurred while running the cleanup', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Run Cleanup Now');
            }
        });
    });

    function updateSortingIndicators(column, order) {
        $('.sort-column').each(function() {
            var $link = $(this);
            var $th = $link.closest('th');
            $th.removeClass('sorted asc desc');
            if ($link.data('column') === column) {
                $th.addClass('sorted ' + order.toLowerCase());
                $link.data('order', order === 'asc' ? 'desc' : 'asc');
            }
        });
    }

    function initializeRowListeners() {
        $('input[name="blocked_paths[]"], input[name="blocked_hosts[]"]').each(function() {
            updateRewriteUrlState($(this).closest('tr'));
        });
    }

    function updateRewriteUrlState($row) {
        var $pathToggle = $row.find('input[name="blocked_paths[]"]');
        var $hostToggle = $row.find('input[name="blocked_hosts[]"]');
        var $rewriteUrl = $row.find('input[name^="rewrite_urls["]');
        
        var isBlocked = $pathToggle.prop('checked') || $hostToggle.prop('checked');
        $rewriteUrl.prop('disabled', isBlocked);
        
        if (isBlocked) {
            var originalValue = originalState.rewrites[$rewriteUrl.attr('name')] || '';
            $rewriteUrl.val(originalValue);
        }
        
        debug("Rewrite URL state updated: " + ($rewriteUrl.attr('name') || 'unknown') + " - Disabled: " + isBlocked);
        
        // Call our new handler to check if the value has changed
        handleRewriteUrlChange($rewriteUrl);
    }
    
    function updateListeningStatus(isListening) {
        $listeningStatus.text(isListening ? 'Listening to outbound calls...' : 'Not listening to outbound calls');
        $listeningStatus.toggleClass('listening', isListening);
    }

    function displayAdminNotice(message, type) {
        var noticeClass = (type === 'error') ? 'notice-error' : 'notice-success';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap > .notice').remove();
        $('.wrap h1').after($notice);
        $(document).on('click', '.notice-dismiss', function() {
            $(this).parent().remove();
        });
    }

    function captureOriginalState() {
        originalState = { hosts: {}, paths: {}, rewrites: {}, settings: {} };
        $('input[name="blocked_hosts[]"]').each(function() {
            originalState.hosts[$(this).val()] = $(this).prop('checked');
        });
        $('input[name="blocked_paths[]"]').each(function() {
            originalState.paths[$(this).val()] = $(this).prop('checked');
        });
        $('input[name^="rewrite_urls["]').each(function() {
            originalState.rewrites[$(this).attr('name')] = $(this).val().trim();
            debug("Captured original rewrite URL: " + $(this).attr('name') + " = " + $(this).val().trim());
        });

        // Capture cleanup settings
        originalState.settings = {
            retention_days: $('#retention_days').val(),
            max_records: $('#max_records').val()
        };
        debug("Original state captured");
    }
    
    function checkForChanges() {
        var hasChanges = false;
        
        debug("Checking for changes...");
    
        // Check blocked hosts and paths
        $('input[name="blocked_hosts[]"], input[name="blocked_paths[]"]').each(function() {
            var name = $(this).attr('name');
            var currentValue = $(this).prop('checked');
            var originalValue = name.includes('blocked_hosts') ? originalState.hosts[$(this).val()] : originalState.paths[$(this).val()];
            
            if (currentValue !== originalValue) {
                debug(name + " changed from '" + originalValue + "' to '" + currentValue + "'");
                hasChanges = true;
                return false; // Exit the loop early
            }
        });
    
        // Check rewrite URLs
        if (!hasChanges) {
            $('input[name^="rewrite_urls["]').each(function() {
                var name = $(this).attr('name');
                var currentValue = $(this).val().trim();
                var originalValue = originalState.rewrites[name] || '';
    
                if (currentValue !== originalValue) {
                    debug(name + " changed from '" + originalValue + "' to '" + currentValue + "'");
                    hasChanges = true;
                    return false; // Exit the loop early
                }
            });
        }

        // Check cleanup settings
        if (!hasChanges) {
            var currentRetentionDays = $('#retention_days').val();
            var currentMaxRecords = $('#max_records').val();
            
            if (currentRetentionDays !== originalState.settings.retention_days) {
                debug("Retention days changed from '" + originalState.settings.retention_days + "' to '" + currentRetentionDays + "'");
                hasChanges = true;
            }
            
            if (currentMaxRecords !== originalState.settings.max_records) {
                debug("Max records changed from '" + originalState.settings.max_records + "' to '" + currentMaxRecords + "'");
                hasChanges = true;
            }
        }
    
        debug("Has changes: " + hasChanges);
        formChanged = hasChanges;
        updateSaveButton();
    }

    function updateToggleAllButton() {
        $('#toggle-all-hosts').text(allHostsToggled ? 'Toggle All Hosts Off' : 'Toggle All Hosts On');
        $('#toggle-all-paths').text(allPathsToggled ? 'Toggle All Paths Off' : 'Toggle All Paths On');
    }

    function syncDomainToggles($toggle) {
        var isChecked = $toggle.prop('checked');
        var domain = $toggle.val();
        $('input[name="blocked_hosts[]"][value="' + domain + '"]').each(function() {
            $(this).prop('checked', isChecked);
            updateRewriteUrlState($(this).closest('tr'));
        });
        checkForChanges();
    }

    function addCustomEntry(entry) {
        var parsed = parseURL(entry);
        if (!parsed) {
            displayAdminNotice('Invalid URL format', 'error');
            return;
        }
        var host = parsed.protocol + '://' + parsed.host;
        var path = parsed.path;
        var fullUrl = host + path;
        
        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: 'add_custom_entry',
                security: outboundCallLogger.nonce,
                entry: fullUrl
            },
            success: function(response) {
                if (response.success) {
                    displayAdminNotice('New entry added successfully', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    displayAdminNotice('Failed to add new entry: ' + response.data.message, 'error');
                }
            },
            error: function() {
                displayAdminNotice('An error occurred while adding the new entry', 'error');
            }
        });
    }

    function parseURL(url) {
        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }
        try {
            const parsedUrl = new URL(url);
            return {
                protocol: parsedUrl.protocol.slice(0, -1),
                host: parsedUrl.host,
                path: parsedUrl.pathname || '/'
            };
        } catch (e) {
            console.error('Invalid URL:', url);
            return null;
        }
    }

    // Event listeners
    $(document).on('click', '.sort-column', function(e) {
        e.preventDefault();
        currentOrderBy = $(this).data('column');
        currentOrder = $(this).data('order');

        var url = new URL(window.location.href);
        url.searchParams.set('orderby', currentOrderBy);
        url.searchParams.set('order', currentOrder);
        window.history.pushState({}, '', url);

        loadRecords($searchInput.val());
    });

    $searchInput.on('input', function() {
        var searchText = $(this).val();
        clearTimeout(searchTimeout);
        if (searchText.length > 2 || searchText.length === 0) {
            searchTimeout = setTimeout(function() {
                loadRecords(searchText);
            }, 300);
        }
    });

    $listeningToggle.on('change', function() {
        var isListening = $(this).is(':checked');
        updateListeningStatus(isListening);

        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: 'toggle_listening',
                nonce: outboundCallLogger.nonce,
                is_listening: isListening
            },
            success: function(response) {
                if (response.success) {
                    displayAdminNotice(response.data.message, 'success');
                } else {
                    displayAdminNotice('Error toggling listening state: ' + response.data.message, 'error');
                    $listeningToggle.prop('checked', !isListening);
                    updateListeningStatus(!isListening);
                }
            },
            error: function() {
                displayAdminNotice('An error occurred while toggling the listening state', 'error');
                $listeningToggle.prop('checked', !isListening);
                updateListeningStatus(!isListening);
            }
        });
    });

    $('#toggle-all-hosts, #toggle-all-paths').on('click', function(e) {
        e.preventDefault();
        var isHosts = this.id === 'toggle-all-hosts';
        var toggleState = isHosts ? allHostsToggled : allPathsToggled;
        $('input[name="blocked_' + (isHosts ? 'hosts' : 'paths') + '[]"]').prop('checked', !toggleState);
        if (isHosts) {
            allHostsToggled = !allHostsToggled;
        } else {
            allPathsToggled = !allPathsToggled;
        }
        updateToggleAllButton();
        checkForChanges();
    });

    $('#export-settings').on('click', function(e) {
        e.preventDefault();
        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: 'export_settings',
                nonce: outboundCallLogger.nonce
            },
            success: function(response) {
                var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(response));
                var downloadAnchorNode = document.createElement('a');
                downloadAnchorNode.setAttribute("href", dataStr);
                downloadAnchorNode.setAttribute("download", "outbound_operator_settings.json");
                document.body.appendChild(downloadAnchorNode);
                downloadAnchorNode.click();
                downloadAnchorNode.remove();
            },
            error: function() {
                displayAdminNotice('An error occurred while exporting settings', 'error');
            }
        });
    });

    $('#import-settings').on('click', function(e) {
        e.preventDefault();
        $('#import-file').click();
    });

    $('#import-file').on('change', function(e) {
        var file = e.target.files[0];
        var reader = new FileReader();
        reader.onload = function(e) {
            var settings = e.target.result;
            $.ajax({
                url: outboundCallLogger.ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_settings',
                    nonce: outboundCallLogger.nonce,
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        displayAdminNotice('Settings imported successfully. Reloading page...', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        displayAdminNotice('Error importing settings: ' + response.data, 'error');
                    }
                },
                error: function() {
                    displayAdminNotice('An error occurred while importing settings', 'error');
                }
            });
        };
        reader.readAsText(file);
    });

    $('#delete-all').on('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to delete all records and their toggle status? This action cannot be undone.')) {
            $.ajax({
                url: outboundCallLogger.ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_all_records',
                    nonce: outboundCallLogger.nonce
                },
                success: function(response) {
                    if (response.success) {
                        sessionStorage.setItem('outboundOperatorDeleted', 'true');
                        window.location.reload();
                    } else {
                        displayAdminNotice('Error deleting records: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    displayAdminNotice('An error occurred while trying to delete records.', 'error');
                }
            });
        }
    });

    $('#add-custom-entry').on('click', function() {
        var entry = $('#custom-entry').val().trim();
        if (entry) {
            addCustomEntry(entry);
        } else {
            displayAdminNotice('Please enter a valid domain or URL', 'error');
        }
    });

    $(document).on('click', '.delete-icon', function() {
        var $icon = $(this);
        var url = $icon.data('url');
        
        if (confirm('Are you sure you want to delete this record?')) {
            $.ajax({
                url: outboundCallLogger.ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_record',
                    security: outboundCallLogger.nonce,
                    url: url
                },
                success: function(response) {
                    if (response.success) {
                        $icon.closest('tr').fadeOut(400, function() {
                            $(this).remove();
                        });
                        displayAdminNotice('Record deleted successfully', 'success');
                        loadRecords();
                    } else {
                        displayAdminNotice('Error deleting record: ' + response.data.message, 'error');
                    }
                },
                error: function() {
                    displayAdminNotice('An error occurred while deleting the record', 'error');
                }
            });
        }
    });
    $('#outbound-operator-form').on('submit', function(e) {
        e.preventDefault();
        var blockedHosts = $('input[name="blocked_hosts[]"]:checked').map(function() { return $(this).val(); }).get();
        var blockedPaths = $('input[name="blocked_paths[]"]:checked').map(function() { return $(this).val(); }).get();
        var rewriteUrls = {};
        $('input[name^="rewrite_urls["]').each(function() {
            var key = $(this).attr('name').match(/\[(.*?)\]/)[1];
            rewriteUrls[key] = $(this).val();
        });

        var retentionDays = $('#retention_days').val();
        var maxRecords = $('#max_records').val();

        $.ajax({
            url: outboundCallLogger.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_blocked_calls',
                security: outboundCallLogger.nonce,
                blocked_hosts: blockedHosts,
                blocked_paths: blockedPaths,
                rewrite_urls: rewriteUrls,
                retention_days: retentionDays,
                max_records: maxRecords
            },
            success: function(response) {
                if (response.success) {
                    displayAdminNotice('Settings saved successfully', 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    displayAdminNotice('Error saving settings: ' + response.data.message, 'error');
                }
            },
            error: function() {
                displayAdminNotice('An error occurred while saving settings', 'error');
            }
        });
    });

    // Initialize
    loadRecords();
    updateListeningStatus($listeningToggle.is(':checked'));
    captureOriginalState();
    checkForChanges();  // This will set the initial state of the Save button
    updateToggleAllButton();

    // Initialize Rewrite URL inputs
    // $('input[name^="rewrite_urls["]').each(function() {
    //     validateRewriteUrl(this);
    // });

    // Check for deletion flag on page load
    if (sessionStorage.getItem('outboundOperatorDeleted') === 'true') {
        displayAdminNotice('All records and blocked settings have been deleted successfully.', 'success');
        sessionStorage.removeItem('outboundOperatorDeleted');
    }

    function initializeCopyButtons() {
        // Remove any existing click handlers first to prevent duplicates
        $(document).off('click', '.copy-url');
        
        // Add click handler for copy buttons
        $(document).on('click', '.copy-url', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $this = $(this);
            var url = $this.data('url');
            
            // Create temporary input element
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val(url).select();
            
            try {
                // Execute copy command
                document.execCommand("copy");
                
                // Visual feedback
                $this.addClass('copied');
                setTimeout(function() {
                    $this.removeClass('copied');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy URL:', err);
            }
            
            // Remove temporary element
            $temp.remove();
        });
    
        // Show/hide copy button on row hover
        $(document).on('mouseenter', '#outbound-calls-table tr', function() {
            $(this).find('.copy-url').css('display', 'inline-flex');
        }).on('mouseleave', '#outbound-calls-table tr', function() {
            $(this).find('.copy-url').css('display', 'none');
        });
    }

    // Custom entry keypress event
    $('#custom-entry').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#add-custom-entry').click();
        }
    });

    // Event listener for host toggles
    $(document).on('change', 'input[name="blocked_hosts[]"]', function() {
        syncDomainToggles($(this));
        allHostsToggled = $('input[name="blocked_hosts[]"]:checked').length === $('input[name="blocked_hosts[]"]').length;
        updateToggleAllButton();
    });

    // Event listener for path toggles
    $(document).on('change', 'input[name="blocked_paths[]"]', function() {
        updateRewriteUrlState($(this).closest('tr'));
        checkForChanges();
        allPathsToggled = $('input[name="blocked_paths[]"]:checked').length === $('input[name="blocked_paths[]"]').length;
        updateToggleAllButton();
    });

    // Rewrite URL validation
    function validateRewriteUrl(input) {
        var $input = $(input);
        var url = $input.val().trim();
        
        if (url === '' || isValidUrl(url)) {
            $input.removeClass('invalid-url');
            setFormChanged();
            return true;
        } else {
            $input.addClass('invalid-url');
            return false;
        }
    }

    function initializeForm() {
        captureOriginalState();
        $('input[name="blocked_paths[]"], input[name="blocked_hosts[]"]').each(function() {
            updateRewriteUrlState($(this).closest('tr'));
        });
        formChanged = false;
        updateSaveButton();
        debug("Form initialized. Save button disabled.");
    }

    // Call this function when the document is ready
    $(document).ready(function() {
        initializeForm();
        initializeCopyButtons();
        
        // Set initial sorting
        currentOrderBy = 'last_call';
        currentOrder = 'DESC';
        
        // Initial load with default sorting
        loadRecords();

        $('#retention_days, #max_records').on('input', function() {
            checkForChanges();
        });
    });

    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch (_) {
            return false;
        }
    }

    // $('input[name^="rewrite_urls["]').on('input', function() {
    //     validateRewriteUrl(this);
    //     setFormChanged();
    // });
});

