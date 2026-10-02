import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

class McpDashboard {
    constructor() {
        this.initCreateToken();
        this.initCopyConfig();
        this.initRevokeTokens();
    }

    initCreateToken() {
        const btn = document.getElementById('createTokenBtn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', () => this.createToken());
    }

    initCopyConfig() {
        document.querySelectorAll('[data-copy-target]').forEach((btn) => {
            btn.addEventListener('click', () => this.copyConfig(btn));
        });
    }

    initRevokeTokens() {
        document.querySelectorAll('.btn-revoke-token').forEach((btn) => {
            btn.addEventListener('click', () => {
                const tokenUid = btn.dataset.tokenUid;
                this.revokeToken(tokenUid, btn);
            });
        });
    }

    async createToken() {
        const clientName = document.getElementById('clientName')?.value || 'Claude Desktop';
        const workspaceUid = document.getElementById('workspaceUid')?.value || '0';

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['aisuite_mcp_create_token'], {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({clientName, workspaceUid}),
            });

            if (!response.ok) {
                Notification.error(
                    TYPO3.lang['aiSuite.module.mcp.createToken'],
                    TYPO3.lang['aiSuite.module.mcp.tokenCreationFailed']
                );
                return;
            }

            const data = await response.json();

            if (data.success) {
                const resultDiv = document.getElementById('tokenResult');
                const configPre = document.getElementById('claudeConfig');

                configPre.textContent = data.claudeDesktopConfig;
                document.getElementById('claudeCodeConfig').textContent = data.claudeCodeConfig;
                resultDiv.style.display = 'block';
                resultDiv.dataset.config = data.claudeDesktopConfig;

                if (data.revokedClientId) {
                    Notification.warning(
                        TYPO3.lang['aiSuite.module.mcp.tokenLimitReached'],
                        TYPO3.lang['aiSuite.module.mcp.tokenLimitRevoked'].replace('%s', data.revokedClientId),
                        15
                    );
                }
            } else {
                Notification.error(
                    TYPO3.lang['aiSuite.module.mcp.createToken'],
                    TYPO3.lang['aiSuite.module.mcp.tokenCreationFailed']
                );
            }
        } catch (error) {
            console.error('Token creation error:', error);
            Notification.error(
                TYPO3.lang['aiSuite.module.mcp.createToken'],
                TYPO3.lang['aiSuite.module.mcp.tokenCreationError']
            );
        }
    }

    async copyConfig(btn) {
        const config = document.getElementById(btn.dataset.copyTarget)?.textContent;
        if (!config) {
            return;
        }

        try {
            await navigator.clipboard.writeText(config);
            const originalText = btn.textContent;
            btn.textContent = TYPO3.lang['aiSuite.module.mcp.copied'];
            setTimeout(() => btn.textContent = originalText, 2000);
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = config;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
    }

    async revokeToken(tokenUid, buttonElement) {
        Modal.confirm(
            TYPO3.lang['aiSuite.module.mcp.revoke'],
            TYPO3.lang['aiSuite.module.mcp.revokeConfirm'],
            Severity.warning,
            [
                {
                    text: TYPO3.lang['aiSuite.module.mcp.revoke'],
                    active: true,
                    btnClass: 'btn-warning',
                    trigger: async () => {
                        Modal.dismiss();
                        await this.executeRevoke(tokenUid, buttonElement);
                    }
                }
            ]
        );
    }

    async executeRevoke(tokenUid, buttonElement) {
        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['aisuite_mcp_revoke_token'], {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({tokenUid}),
            });

            if (!response.ok) {
                Notification.error(
                    TYPO3.lang['aiSuite.module.mcp.revoke'],
                    TYPO3.lang['aiSuite.module.mcp.tokenRevocationError']
                );
                return;
            }

            const data = await response.json();

            if (data.success) {
                const row = document.getElementById('token-row-' + tokenUid);
                if (row) {
                    row.style.opacity = '0.3';
                    row.style.textDecoration = 'line-through';
                    buttonElement.disabled = true;
                    buttonElement.textContent = TYPO3.lang['aiSuite.module.mcp.revoked'];
                }
            }
        } catch (error) {
            console.error('Token revocation error:', error);
            Notification.error(
                TYPO3.lang['aiSuite.module.mcp.revoke'],
                TYPO3.lang['aiSuite.module.mcp.tokenRevocationError']
            );
        }
    }
}

export default new McpDashboard();
