<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Netkit - Open-Source PDF Editor & Admin Dashboard</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-white dark:bg-gray-900 antialiased">
        <!-- Header -->
        <x-site-header />


        <!-- Hero Section -->
        <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-b from-white to-gray-50 dark:from-gray-900 dark:to-gray-800">
            <div class="container mx-auto">
                <div class="text-center max-w-4xl mx-auto mb-16">
                    <h1 class="text-5xl md:text-6xl lg:text-7xl font-bold text-gray-900 dark:text-white mb-6">
                        Powerful <span class="text-blue-600">AI enabled tools</span><br>for the NET
                    </h1>
                    <p class="text-xl text-gray-600 dark:text-gray-300 mb-8 max-w-2xl mx-auto">
                        Netkit aims to provide security driven, customer focused tools that are as affordable as they are powerful.
                    </p>
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-12">
                        
                    </div>
                    <div class="flex items-center justify-center gap-4">
                       
                        
                    </div>
                </div>

                <!-- Hero Image -->
                <div class="relative max-w-6xl mx-auto">
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden bg-white dark:bg-gray-800">
                        <img src="https://tailadmin.com/_next/image?url=%2F_next%2Fstatic%2Fmedia%2Fmain-image.393386f9.png&w=1920&q=75" alt="Dashboard Preview" class="w-full">
                    </div>
                    <div class="absolute -bottom-6 left-10 right-10 h-12 bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 rounded-lg blur-3xl opacity-50"></div>
                </div>
            </div>
        </section>

        <!-- Trusted By Section -->
        <section id="pdf-features" class="py-16 px-4 sm:px-6 lg:px-8 bg-white dark:bg-gray-900">
            <div class="container mx-auto">
                <p class="text-center text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-8">
                    Trusted by over 80,000 individuals and companies worldwide
                </p>
                <div class="flex flex-wrap items-center justify-center gap-8 opacity-50 grayscale">
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Alibaba</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">NVIDIA</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Dolby</div>
                    <div class="text-3xl font-bold text-gray-800 dark:text-gray-200">Pexels</div>
                </div>
            </div>
        </section>

        <!-- PDF Features Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-800">
            <div class="container mx-auto">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 dark:text-white mb-4">
                        Powerful PDF Editing Features
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-300">
                        Everything you need to edit, annotate, and manage your PDF documents
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 max-w-7xl mx-auto">
                    <!-- Edit Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Edit</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Directly edit text, images, and content within your PDF documents with precision and ease.
                        </p>
                    </div>

                    <!-- AI Generate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">AI Generate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Leverage AI to automatically generate content, summaries, and intelligent document enhancements.
                        </p>
                    </div>

                    <!-- Merge Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Merge</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Combine multiple PDF documents into a single file seamlessly with drag-and-drop simplicity.
                        </p>
                    </div>

                    <!-- Protect Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Protect</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Secure your documents with password protection and encryption to keep sensitive data safe.
                        </p>
                    </div>

                    <!-- Draw Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Draw</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Freehand drawing tools to sketch, highlight, and add custom visual elements to your PDFs.
                        </p>
                    </div>

                    <!-- Annotate Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Annotate</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add comments, notes, and markup to collaborate and provide feedback on PDF documents.
                        </p>
                    </div>

                    <!-- Sign Feature -->
                    <div class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group">
                        <div class="w-14 h-14 bg-pink-100 dark:bg-pink-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-pink-600 dark:text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Sign</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Add legally binding electronic signatures to documents with full compliance and security.
                        </p>
                    </div>

                    <!-- Domain Search Feature -->
                    <a href="{{ route('domainSearch.index') }}" class="bg-white dark:bg-gray-900 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group block">
                        <div class="w-14 h-14 bg-cyan-100 dark:bg-cyan-900/30 rounded-xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                            <svg class="w-8 h-8 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-3">Domain Search</h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Search for available domain names and generate creative suggestions for your next project.
                        </p>
                    </a>

                    <!-- Get Started CTA Card -->
                    <div class="bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl p-8 shadow-lg hover:shadow-xl transition group flex flex-col justify-center items-center text-center">
                        <h3 class="text-2xl font-bold text-white mb-3">Ready to Start?</h3>
                        <p class="text-blue-100 text-sm mb-6">
                            Access all features now
                        </p>
                        <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 rounded-lg font-semibold hover:bg-gray-100 transition">
                            Open Editor
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-blue-600 via-blue-700 to-purple-700 text-white">
            <div class="container mx-auto text-center">
                <h2 class="text-4xl md:text-5xl font-bold mb-6">
                    Join thousands using the #1<br>PDF Editor & Admin Dashboard!
                </h2>
                <p class="text-xl text-blue-100 mb-8 max-w-2xl mx-auto">
                    Start building amazing PDF editing experiences and admin panels today
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/admin/login" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 rounded-lg font-semibold text-lg transition shadow-xl">
                        Login
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                    </a>
                    <a href="{{ route('documents.index') }}" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white hover:bg-white/10 text-white rounded-lg font-semibold text-lg transition">
                        Live Preview
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section id="faq" class="py-20 px-4 sm:px-6 lg:px-8 bg-white dark:bg-gray-900">
            <div class="container mx-auto">
                <div class="text-center max-w-3xl mx-auto mb-16">
                    <h2 class="text-4xl md:text-5xl font-bold text-gray-900 dark:text-white mb-4">
                        Frequently Asked Questions
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-300">
                        Find answers to common questions about our AI-enabled tools and services
                    </p>
                </div>
                
                <div class="max-w-4xl mx-auto">
                    <div class="space-y-6">
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                What tools are included in Netkit?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Netkit includes a comprehensive PDF editor, domain search capabilities, and various AI-enabled tools for document management and business operations.
                            </p>
                        </div>
                        
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                Is Netkit secure and reliable?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Yes, Netkit is built with security as a priority. We implement industry-standard encryption and security practices to protect your data.
                            </p>
                        </div>
                        
                        <div class="py-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                                How affordable are your tools?
                            </h3>
                            <p class="text-gray-600 dark:text-gray-300">
                                Our tools are designed to be as affordable as they are powerful, with flexible pricing options to suit different needs and budgets.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-12 px-4 sm:px-6 lg:px-8 bg-gray-900 text-gray-300">
            <div class="container mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="text-xl font-bold text-white">Toolbase</span>
                        </div>
                        <p class="text-sm">Free and Open-Source PDF Editor & Admin Dashboard Template</p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Useful Links</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Documentation</a></li>
                            <li><a href="#" class="hover:text-white transition">Blog</a></li>
                            <li><a href="#" class="hover:text-white transition">Update Logs</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">About</h3>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
                            <li><a href="#" class="hover:text-white transition">License</a></li>
                            <li><a href="#" class="hover:text-white transition">Support</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white mb-4">Newsletter</h3>
                        <p class="text-sm mb-4">Subscribe for the latest updates</p>
                        <input type="email" placeholder="Enter your email" class="w-full px-4 py-2 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none text-sm">
                    </div>
                </div>
                <div class="border-t border-gray-800 pt-8 text-center text-sm">
                    <p>&copy; 2026 Netkit - All Rights Reserved.</p>
                </div>
            </div>
        </footer>
    </body>
</html>
