<div class="tab-content{{ ($activeTab ?? '') === 'extracted-text' ? ' active' : '' }}" id="extracted-text" @if(($activeTab ?? 'pdf-editor') !== 'extracted-text') style="display:none;" @endif>
    @if(!$editorIsAuthenticated)
        <div class="absolute inset-0 z-50 flex items-center justify-center backdrop-blur-sm bg-gray-900/50">
            <div class="p-8 text-center bg-gray-800 border border-gray-700 shadow-2xl rounded-2xl max-w-md mx-4">
                <div class="mb-4 inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10">
                    <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <h3 class="mb-2 text-2xl font-bold text-white">Sign in required</h3>
                <p class="mb-6 text-gray-400">Please sign in to your account to use the AI Generator features.</p>
                <div class="flex flex-col gap-3">
                    <a href="{{ route('login') }}" class="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white font-semibold rounded-lg transition-colors">
                        Sign In
                    </a>
                    <a href="{{ route('register') }}" class="px-6 py-2.5 bg-gray-700 hover:bg-gray-600 text-white font-semibold rounded-lg transition-colors">
                        Create Account
                    </a>
                </div>
            </div>
        </div>
    @endif
    <div class="layout" @if(!$editorIsAuthenticated) style="pointer-events: none; opacity: 0.5;" @endif>
        <!-- AI Chat Sidebar -->
        <aside class="ai-chat-sidebar">
            <div class="ai-chat-header">AI Assistant</div>
            <div class="ai-chat-messages" id="ai-chat-messages">
                <!-- Messages will be dynamically added here -->
            </div>

            <!-- Request History Panel -->
            <div class="ai-request-history-panel" id="ai-request-history-panel">
                <div class="history-header" id="history-header">
                    <span>💰 Request History</span>
                    <button class="history-toggle" id="history-toggle">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                </div>
                <div class="history-content" id="history-content" style="display: none;">
                    <div class="history-list" id="history-list">
                        <!-- History items will be added here -->
                    </div>
                </div>
            </div>

            <div class="ai-chat-input-area">
                <div class="ai-chat-input-wrapper">
                    <div class="ai-chat-input-container">
                        <textarea
                            id="ai-chat-input"
                            class="ai-chat-textarea"
                            placeholder="Type your prompt here..."
                            rows="1"
                        ></textarea>
                        <button class="ai-attach-btn" id="ai-attach-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                            </svg>
                            Attach
                        </button>
                    </div>
                    <button class="ai-send-btn" id="ai-send-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 19V5M5 12l7-7 7 7"/>
                        </svg>
                    </button>
                </div>
            </div>
        </aside>

        <main class="viewer-wrap" style="margin-left: 0;">
            <div class="sticky-tools">
                <div class="mode-bar" id="ai-mode-bar">
                    <button type="button" id="generate-from-template" class="primary">
                        <span class="icon">📄</span>
                        Generate from Template
                    </button>
                    <button type="button" id="customize-prompt-btn" class="ghost">
                        <span class="icon">⚙️</span>
                        Customize Prompt
                    </button>
                    <span class="mode-spacer"></span>
                    <button id="ai-add-to-pdf-btn" class="primary" type="button">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        Add to PDF
                    </button>
                </div>
            </div>
            <div class="viewer" id="ai-viewer"></div>
        </main>
    </div>
</div>
