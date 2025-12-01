/*
 * Handles client-side authentication for SmartTutor static pages.
 * - Connects register & login forms to the PHP API
 * - Stores JWT and user snapshot securely in storage
 * - Guards dashboard pages and hydrates header details
 */
(function() {
    'use strict';

    var STORAGE_KEYS = {
        token: 'smartTutorAuthToken',
        user: 'smartTutorActiveUser',
        profileCache: 'smartTutorProfileMeta'
    };

    var REDIRECT_PATHS = {
        student: 'studentProfile.html',
        tutor: 'tutorProfile.html'
    };

    var AUTH_PAGES = {
        signin: 'sign-in.html',
        register: 'register.html'
    };

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function() {
        initRoleToggle();
        initPasswordToggles();
        enhanceCheckboxGroups();
        initRegisterForm();
        initSignInForm();
        guardDashboard();
        hydrateDashboardHeader();
    });

    /* ---------- Form Enhancements ---------- */

    function initRoleToggle() {
        var roleButtons = document.querySelectorAll('[data-role-pill]');
        var hiddenField = document.querySelector('[data-role-input]');
        var sections = document.querySelectorAll('[data-role-section]');
        var roleLabel = document.querySelector('[data-role-label]');

        if (!roleButtons.length || !hiddenField) {
            return;
        }

        var defaultRole = hiddenField.value || roleButtons[0].getAttribute('data-role-pill');
        updateRole(defaultRole);

        roleButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                var role = button.getAttribute('data-role-pill');
                updateRole(role);
            });
        });

        function updateRole(role) {
            hiddenField.value = role;

            roleButtons.forEach(function(button) {
                var isActive = button.getAttribute('data-role-pill') === role;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-pressed', String(isActive));
            });

            sections.forEach(function(section) {
                var matches = section.getAttribute('data-role-section') === role;
                section.hidden = !matches;
                section.classList.toggle('is-hidden', !matches);
            });

            if (roleLabel) {
                var text = roleLabel.getAttribute('data-role-label-' + role);
                if (text) {
                    roleLabel.textContent = text;
                }
            }
        }
    }

    function initPasswordToggles() {
        var toggles = document.querySelectorAll('[data-password-toggle]');

        toggles.forEach(function(toggle) {
            var target = document.getElementById(toggle.getAttribute('data-password-toggle'));
            if (!target) {
                return;
            }

            toggle.addEventListener('click', function() {
                var isHidden = target.type === 'password';
                target.type = isHidden ? 'text' : 'password';
                toggle.textContent = isHidden ? 'Hide' : 'Show';
                target.focus();
            });
        });
    }

    function enhanceCheckboxGroups() {
        var chipGroups = document.querySelectorAll('.chip-group');
        chipGroups.forEach(function(group) {
            group.setAttribute('role', 'group');
        });
    }

    /* ---------- Registration Flow ---------- */

    function initRegisterForm() {
        var form = document.querySelector('[data-register-form]');
        if (!form) {
            return;
        }

        var feedbackTarget = form.querySelector('[data-form-feedback]');
        var roleField = form.querySelector('[data-role-input]');

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            clearFormFeedback(feedbackTarget);

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var formData = new FormData(form);
            var password = String(formData.get('password') || '');
            var confirmPassword = String(formData.get('password_confirmation') || '');

            if (password !== confirmPassword) {
                setFormFeedback(feedbackTarget, 'Passwords do not match. Please check and try again.');
                focusField(form, 'password_confirmation');
                return;
            }

            var email = normaliseEmail(formData.get('email'));
            if (!email) {
                setFormFeedback(feedbackTarget, 'Please provide a valid email address.');
                focusField(form, 'email');
                return;
            }

            var role = roleField ? roleField.value : 'student';
            role = role === 'tutor' ? 'tutor' : 'student';

            var payload = {
                email: email,
                password: password,
                role: role,
                first_name: (formData.get('first_name') || '').toString().trim(),
                last_name: (formData.get('last_name') || '').toString().trim(),
                name: buildFullNameFromFields(formData)
            };

            toggleFormSubmitting(form, true);

            apiFetch('/auth.php?action=register', {
                    method: 'POST',
                    auth: false,
                    json: payload
                })
                .then(function(response) {
                    var user = enrichUserFromResponse(response.user, role, form);
                    storeProfileMeta(user.email, user.profileLine, user.meta);
                    storeSession(response.token, user, false);
                    setFormFeedback(feedbackTarget, response.message || 'Account created successfully.', 'success');

                    window.setTimeout(function() {
                        window.location.href = resolveDashboardPath(user.role);
                    }, 600);
                })
                .catch(function(error) {
                    handleFormError(form, feedbackTarget, error);
                })
                .finally(function() {
                    toggleFormSubmitting(form, false);
                });
        });
    }

    /* ---------- Sign-in Flow ---------- */

    function initSignInForm() {
        var form = document.querySelector('[data-signin-form]');
        if (!form) {
            return;
        }

        var feedbackTarget = form.querySelector('[data-form-feedback]');

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            clearFormFeedback(feedbackTarget);

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var emailField = form.querySelector('#signinEmail');
            var passwordField = form.querySelector('#signinPassword');

            var email = normaliseEmail(emailField ? emailField.value : '');
            var password = String(passwordField ? passwordField.value : '');

            if (!email) {
                setFormFeedback(feedbackTarget, 'Please enter your email address.');
                focusField(form, 'email');
                return;
            }

            if (!password) {
                setFormFeedback(feedbackTarget, 'Please enter your password.');
                focusField(form, 'password');
                return;
            }

            var rememberCheckbox = form.querySelector('#rememberMe');
            var rememberMe = rememberCheckbox ? Boolean(rememberCheckbox.checked) : false;

            toggleFormSubmitting(form, true);

            apiFetch('/auth.php?action=login', {
                    method: 'POST',
                    auth: false,
                    json: {
                        email: email,
                        password: password
                    }
                })
                .then(function(response) {
                    var user = enrichUserFromCache(response.user);
                    storeSession(response.token, user, rememberMe);
                    setFormFeedback(feedbackTarget, response.message || 'Signing you in...', 'success');

                    window.setTimeout(function() {
                        window.location.href = resolveDashboardPath(user.role);
                    }, 400);
                })
                .catch(function(error) {
                    handleFormError(form, feedbackTarget, error);
                })
                .finally(function() {
                    toggleFormSubmitting(form, false);
                });
        });
    }

    function handleFormError(form, feedbackTarget, error) {
        var message = getErrorMessage(error);
        setFormFeedback(feedbackTarget, message);

        if (error && error.meta && error.meta.field) {
            focusField(form, error.meta.field);
        }
    }

    /* ---------- Dashboard Guard ---------- */

    function guardDashboard() {
        if (!document.body) {
            return;
        }

        var expectedRole = document.body.getAttribute('data-dashboard-role');
        if (!expectedRole) {
            return;
        }

        var token = getSessionToken();
        if (!token) {
            redirectToSignIn();
            return;
        }

        apiFetch('/auth.php', {
                method: 'GET'
            })
            .then(function(response) {
                var user = enrichUserFromCache(response.user);

                if (user.role !== expectedRole) {
                    window.location.href = resolveDashboardPath(user.role);
                    return;
                }

                updateStoredUser(user);
                hydrateDashboardHeader(user);
            })
            .catch(function() {
                clearSession();
                redirectToSignIn();
            });
    }

    function hydrateDashboardHeader(explicitUser) {
        if (!document.body || !document.body.getAttribute('data-dashboard-role')) {
            return;
        }

        var user = explicitUser || getStoredUser();
        if (!user) {
            return;
        }

        var nameEl = document.querySelector('[data-user-name]');
        if (nameEl) {
            nameEl.textContent = user.name || buildFullNameFromUser(user) || user.email;
        }

        var metaEl = document.querySelector('[data-user-meta]');
        if (metaEl) {
            var metaDescription = user.profileLine || null;
            if (!metaDescription) {
                var cachedMeta = getProfileMeta(user.email);
                if (cachedMeta && cachedMeta.profileLine) {
                    metaDescription = cachedMeta.profileLine;
                } else {
                    metaDescription = '';
                }
            }

            if (!metaDescription) {
                metaDescription = user.role === 'tutor' ? 'Tutor' : 'Student';
            }

            metaEl.textContent = metaDescription;
        }
    }

    /* ---------- Storage Helpers ---------- */

    function storeSession(token, user, remember) {
        if (!token || !user) {
            return;
        }

        var primary = remember ? localStorage : sessionStorage;
        var secondary = remember ? sessionStorage : localStorage;

        try {
            primary.setItem(STORAGE_KEYS.token, token);
            primary.setItem(STORAGE_KEYS.user, JSON.stringify(user));
            secondary.removeItem(STORAGE_KEYS.token);
            secondary.removeItem(STORAGE_KEYS.user);
        } catch (error) {
            // Ignore storage errors in constrained environments
        }
    }

    function updateStoredUser(user) {
        if (!user) {
            return;
        }

        var targetStorage = localStorage.getItem(STORAGE_KEYS.token) ? localStorage : sessionStorage;
        try {
            targetStorage.setItem(STORAGE_KEYS.user, JSON.stringify(user));
        } catch (error) {
            // ignore
        }
    }

    function getSessionToken() {
        return sessionStorage.getItem(STORAGE_KEYS.token) || localStorage.getItem(STORAGE_KEYS.token) || '';
    }

    function getStoredUser() {
        var raw = sessionStorage.getItem(STORAGE_KEYS.user) || localStorage.getItem(STORAGE_KEYS.user);
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    function clearSession() {
        sessionStorage.removeItem(STORAGE_KEYS.token);
        sessionStorage.removeItem(STORAGE_KEYS.user);
        localStorage.removeItem(STORAGE_KEYS.token);
        localStorage.removeItem(STORAGE_KEYS.user);
    }

    /* ---------- Profile Caching ---------- */

    function storeProfileMeta(email, profileLine, meta) {
        if (!email) {
            return;
        }

        var cache = getProfileCache();
        cache[email] = {
            profileLine: profileLine,
            meta: meta || null
        };

        try {
            localStorage.setItem(STORAGE_KEYS.profileCache, JSON.stringify(cache));
        } catch (error) {
            // ignore
        }
    }

    function getProfileMeta(email) {
        var cache = getProfileCache();
        return cache[email] || null;
    }

    function getProfileCache() {
        var raw = localStorage.getItem(STORAGE_KEYS.profileCache);
        if (!raw) {
            return {};
        }

        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    /* ---------- API Helpers ---------- */

    function apiFetch(path, options) {
        options = options || {};
        var base = resolveApiBase();
        var target = joinUrl(base, path);
        var headers = Object.assign({}, options.headers || {});
        var method = (options.method || 'GET').toUpperCase();
        var body = options.body;

        if (options.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(options.json);
        } else if (body && !(body instanceof FormData) && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
            if (typeof body !== 'string') {
                body = JSON.stringify(body);
            }
        }

        if (options.auth !== false) {
            var token = getSessionToken();
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
        }

        return fetch(target, {
                method: method,
                headers: headers,
                body: method === 'GET' || method === 'HEAD' ? undefined : body
            })
            .then(parseApiResponse);
    }

    function parseApiResponse(response) {
        return response.text().then(function(text) {
            var data = {};
            if (text) {
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw createApiError('Invalid server response.', response.status);
                }
            }

            if (!response.ok || data.status === 'error') {
                var message = data.message || 'Request failed. Please try again.';
                var err = createApiError(message, response.status, data.meta || {});
                throw err;
            }

            return data;
        });
    }

    function createApiError(message, status, meta) {
        var error = new Error(message);
        error.status = status;
        error.meta = meta || {};
        return error;
    }

    /* ---------- Utility Helpers ---------- */

    function toggleFormSubmitting(form, isSubmitting) {
        var buttons = form.querySelectorAll('button[type="submit"]');
        buttons.forEach(function(button) {
            button.disabled = isSubmitting;
            button.setAttribute('aria-busy', String(isSubmitting));
        });
    }

    function setFormFeedback(target, message, type) {
        if (!target || !message) {
            return;
        }

        target.classList.remove('form-feedback--error', 'form-feedback--success');
        var variant = type === 'success' ? 'form-feedback--success' : 'form-feedback--error';
        target.classList.add(variant);
        target.textContent = message;
        target.hidden = false;
    }

    function clearFormFeedback(target) {
        if (!target) {
            return;
        }

        target.hidden = true;
        target.textContent = '';
        target.classList.remove('form-feedback--error', 'form-feedback--success');
    }

    function focusField(form, name) {
        if (!form || !name) {
            return;
        }

        var field = form.querySelector('[name="' + name + '"]');
        if (field) {
            field.focus();
        }
    }

    function normaliseEmail(value) {
        return (value || '').toString().trim().toLowerCase();
    }

    function buildFullNameFromFields(formData) {
        var first = (formData.get('first_name') || '').toString().trim();
        var last = (formData.get('last_name') || '').toString().trim();
        return [first, last].filter(Boolean).join(' ');
    }

    function buildFullNameFromUser(user) {
        if (!user) {
            return '';
        }

        if (user.name) {
            return user.name;
        }

        var parts = [];
        if (user.firstName) {
            parts.push(user.firstName);
        }
        if (user.lastName) {
            parts.push(user.lastName);
        }
        return parts.join(' ');
    }

    function getErrorMessage(error) {
        if (!error) {
            return 'Something went wrong. Please try again.';
        }
        if (typeof error === 'string') {
            return error;
        }
        if (error.message) {
            return error.message;
        }
        return 'Something went wrong. Please try again.';
    }

    function resolveDashboardPath(role) {
        return REDIRECT_PATHS[role] || REDIRECT_PATHS.student;
    }

    function redirectToSignIn() {
        window.location.href = AUTH_PAGES.signin;
    }

    function resolveApiBase() {
        if (typeof document !== 'undefined' && document.body) {
            var attr = document.body.getAttribute('data-api-base');
            if (attr) {
                return attr.replace(/\/$/, '');
            }
        }
        return '/api';
    }

    function joinUrl(base, path) {
        if (!base.endsWith('/')) {
            base += '/';
        }
        if (path.charAt(0) === '/') {
            path = path.substring(1);
        }
        return base + path;
    }

    /* ---------- User Enrichment ---------- */

    function enrichUserFromResponse(user, role, form) {
        var enriched = Object.assign({}, user);
        enriched.role = role;
        var names = splitName(user.name || '');
        if (form) {
            var firstInput = form.querySelector('[name="first_name"]');
            var lastInput = form.querySelector('[name="last_name"]');
            enriched.firstName = firstInput ? firstInput.value.trim() : names.firstName;
            enriched.lastName = lastInput ? lastInput.value.trim() : names.lastName;
        } else {
            enriched.firstName = names.firstName;
            enriched.lastName = names.lastName;
        }

        var meta = role === 'tutor' ? collectTutorMeta(form) : collectStudentMeta(form);
        enriched.meta = meta;
        enriched.profileLine = createProfileLine(role, meta);
        return enriched;
    }

    function enrichUserFromCache(user) {
        var enriched = Object.assign({}, user);
        var cached = getProfileMeta(user.email);
        if (cached) {
            enriched.profileLine = cached.profileLine;
            enriched.meta = cached.meta;
        }

        var names = splitName(user.name || '');
        enriched.firstName = names.firstName;
        enriched.lastName = names.lastName;
        return enriched;
    }

    function splitName(fullName) {
        var trimmed = (fullName || '').trim();
        if (!trimmed) {
            return { firstName: '', lastName: '' };
        }

        var parts = trimmed.split(/\s+/);
        if (parts.length === 1) {
            return { firstName: parts[0], lastName: '' };
        }

        return {
            firstName: parts[0],
            lastName: parts.slice(1).join(' ')
        };
    }

    function collectStudentMeta(form) {
        if (!form) {
            return {};
        }

        var meta = {};
        var levelSelect = form.querySelector('#studentLevel');
        var goalSelect = form.querySelector('#studentGoal');
        var scheduleSelect = form.querySelector('#studentSchedule');
        var modeSelect = form.querySelector('#studentMode');

        meta.level = levelSelect ? levelSelect.value : '';
        meta.levelLabel = getSelectedOptionLabel(levelSelect);
        meta.goal = goalSelect ? goalSelect.value : '';
        meta.goalLabel = getSelectedOptionLabel(goalSelect);
        meta.schedule = scheduleSelect ? scheduleSelect.value : '';
        meta.scheduleLabel = getSelectedOptionLabel(scheduleSelect);
        meta.mode = modeSelect ? modeSelect.value : '';
        meta.modeLabel = getSelectedOptionLabel(modeSelect);
        meta.subjects = collectCheckedOptions(form, 'subjects[]');

        return meta;
    }

    function collectTutorMeta(form) {
        if (!form) {
            return {};
        }

        var meta = {};
        var expertiseSelect = form.querySelector('#tutorExpertise');
        var experienceSelect = form.querySelector('#tutorExperience');
        var rateInput = form.querySelector('#tutorRate');

        meta.expertise = expertiseSelect ? expertiseSelect.value : '';
        meta.expertiseLabel = getSelectedOptionLabel(expertiseSelect);
        meta.experience = experienceSelect ? experienceSelect.value : '';
        meta.experienceLabel = getSelectedOptionLabel(experienceSelect);
        meta.sessions = collectCheckedOptions(form, 'session_types[]');
        meta.rate = rateInput && rateInput.value ? rateInput.value : '';

        return meta;
    }

    function collectCheckedOptions(form, fieldName) {
        return Array.prototype.slice.call(form.querySelectorAll('input[name="' + fieldName + '"]:checked')).map(function(input) {
            return input.nextElementSibling ? input.nextElementSibling.textContent.trim() : input.value;
        });
    }

    function getSelectedOptionLabel(select) {
        if (!select || !select.options || select.selectedIndex < 0) {
            return '';
        }

        var option = select.options[select.selectedIndex];
        if (!option || !option.value) {
            return '';
        }

        return option.textContent.trim();
    }

    function createProfileLine(role, meta) {
        var parts = [];

        if (role === 'student') {
            if (meta.levelLabel) {
                parts.push(meta.levelLabel);
            }
            if (meta.goalLabel) {
                parts.push(meta.goalLabel);
            }
            if (meta.modeLabel) {
                parts.push(meta.modeLabel);
            }
            parts.push('Student');
            return parts.filter(Boolean).join(' · ');
        }

        if (meta.expertiseLabel) {
            parts.push(meta.expertiseLabel);
        }
        if (meta.experienceLabel) {
            parts.push(meta.experienceLabel);
        }
        parts.push('Tutor');
        return parts.filter(Boolean).join(' · ');
    }
})();