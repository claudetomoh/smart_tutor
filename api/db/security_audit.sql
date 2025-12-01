-- Audit checklist table
CREATE TABLE IF NOT EXISTS audit_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    item VARCHAR(255) NOT NULL,
    description TEXT,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    remediation_steps TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Checklist results table
CREATE TABLE IF NOT EXISTS checklist_results (
    checklist_id INT,
    audit_date DATE,
    status ENUM('pass', 'fail', 'na', 'pending') NOT NULL,
    notes TEXT,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (checklist_id, audit_date),
    FOREIGN KEY (checklist_id) REFERENCES audit_checklist(id)
);

-- Security metrics table for historical data
CREATE TABLE IF NOT EXISTS security_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_score INT NOT NULL,
    active_threats INT NOT NULL DEFAULT 0,
    resolved_threats INT NOT NULL DEFAULT 0,
    failed_logins INT NOT NULL DEFAULT 0,
    successful_logins INT NOT NULL DEFAULT 0,
    compliance_score DECIMAL(5,2),
    vulnerability_count INT NOT NULL DEFAULT 0,
    incident_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sample audit checklist items
INSERT INTO audit_checklist 
(category, item, description, severity, remediation_steps) 
VALUES
('Authentication', 'Password Policy', 'Verify password complexity requirements are enforced', 'high', 
 'Update password policy in Security class constants. Ensure MIN_PASSWORD_LENGTH >= 12, REQUIRE_UPPERCASE = true, etc.'),

('Authentication', '2FA Implementation', 'Verify two-factor authentication is properly implemented', 'critical',
 'Check TwoFactor class implementation. Ensure backup codes are securely stored and rate limiting is in place.'),

('Access Control', 'Role-Based Access', 'Verify role-based access control is implemented for all sensitive operations', 'high',
 'Review SecurityAudit class and ensure all admin operations check user roles.'),

('Data Protection', 'Data Encryption', 'Verify sensitive data is encrypted at rest and in transit', 'critical',
 'Implement encryption for sensitive database fields. Ensure HTTPS is enforced.'),

('Monitoring', 'Security Logging', 'Verify security events are properly logged and monitored', 'medium',
 'Check SecurityLogger implementation. Ensure all security events are captured with proper context.'),

('Incident Response', 'Incident Handling', 'Verify incident response procedures are documented and tested', 'high',
 'Review IncidentResponse class implementation. Test automated response capabilities.'),

('Compliance', 'Regular Audits', 'Verify regular security audits are conducted and documented', 'medium',
 'Implement automated security audits. Store results in compliance_audits table.'),

('API Security', 'API Authentication', 'Verify API endpoints require proper authentication', 'critical',
 'Review all API endpoints. Ensure JWT validation is implemented correctly.'),

('Session Management', 'Session Security', 'Verify secure session handling practices', 'high',
 'Check session configuration. Implement session timeout and secure cookie settings.'),

('Error Handling', 'Secure Error Handling', 'Verify proper error handling without information leakage', 'medium',
 'Review error handling. Ensure sensitive information is not exposed in error messages.');