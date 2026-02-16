<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' || false }" x-init="$watch('darkMode', val => document.documentElement.classList.toggle('dark', val)); document.documentElement.classList.toggle('dark', darkMode)" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Logo Editor - Netkit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .tool-btn { border: 1px solid rgb(209 213 219); background: white; color: rgb(31 41 55); }
        .dark .tool-btn { border-color: rgb(55 65 81); background: rgb(31 41 55); color: rgb(229 231 235); }
        .tool-btn.active { background: rgb(16 185 129); color: white; border-color: rgb(16 185 129); }
        .editor-stage { position: relative; border-radius: 14px; overflow: hidden; border: 1px solid rgb(209 213 219); background: repeating-conic-gradient(#d1d5db 0% 25%, #f3f4f6 0% 50%) 50% / 18px 18px; }
        .dark .editor-stage { border-color: rgb(55 65 81); }
        canvas { display: block; width: 100%; height: auto; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-950 antialiased min-h-screen">
    <x-site-header :compact="true" :show-navigation="false" :show-auth-controls="false" brand="NetKit" />

    <main class="pt-28 pb-16" x-data="logoEditor()" x-init="init()">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-[1200px]">
            <div class="mb-6 flex flex-wrap items-center gap-3 justify-between">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">Logo Editor</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Request #{{ $logoRequest->id }} · {{ $logoRequest->domain }}</p>
                </div>
                <a href="{{ route('domainSearch.logoGenerator') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    Back to Logo Generator
                </a>
            </div>

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm p-4 sm:p-5">
                <div class="flex flex-wrap gap-2 mb-3">
                    <button type="button" class="tool-btn px-3 py-2 rounded-lg text-sm font-medium" :class="{ 'active': mode === 'select' }" @click="setMode('select')">Select</button>
                    <button type="button" class="tool-btn px-3 py-2 rounded-lg text-sm font-medium" :class="{ 'active': mode === 'text' }" @click="setMode('text')">Add Text</button>
                    <button type="button" class="tool-btn px-3 py-2 rounded-lg text-sm font-medium" :class="{ 'active': mode === 'shape' }" @click="setMode('shape')">Shapes</button>
                    <button type="button" class="tool-btn px-3 py-2 rounded-lg text-sm font-medium" :class="{ 'active': mode === 'draw' }" @click="setMode('draw')">Draw</button>
                    <button type="button" class="px-3 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300" @click="undo()" :disabled="historyIndex <= 0">Undo</button>
                    <button type="button" class="px-3 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300" @click="redo()" :disabled="historyIndex >= history.length - 1">Redo</button>
                    <button type="button" class="px-3 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300" @click="resetEdits()">Reset</button>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold bg-green-600 hover:bg-green-700 text-white transition" @click="saveEditedLogo()" :disabled="saving">
                        <span x-text="saving ? 'Saving...' : 'Save Edited Logo'"></span>
                    </button>
                </div>

                <div class="flex flex-wrap gap-3 mb-4 items-center">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Shape</label>
                    <select x-model="shapeType" class="px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200">
                        <option value="rect">Rectangle</option>
                        <option value="circle">Circle</option>
                        <option value="line">Line</option>
                    </select>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Color</label>
                    <input type="color" x-model="activeColor" class="w-9 h-9 rounded border border-gray-300 dark:border-gray-700 p-0" />
                    <label class="text-xs text-gray-500 dark:text-gray-400">Size</label>
                    <input type="range" min="1" max="24" x-model.number="activeSize" class="w-28" />
                    <label class="text-xs text-gray-500 dark:text-gray-400">Eraser</label>
                    <input type="checkbox" x-model="eraserMode" class="rounded border-gray-300 dark:border-gray-700" />
                </div>

                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach ($imageUrls as $idx => $url)
                        <a href="{{ route('logos.edit', ['logoRequest' => $logoRequest->id, 'image' => $idx]) }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium border transition {{ $activeImageIndex === $idx ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300' }}">
                            Image {{ $idx + 1 }}
                        </a>
                    @endforeach
                </div>

                <div id="editor-stage" class="editor-stage">
                    <canvas id="logo-canvas"></canvas>
                </div>

                <p class="mt-3 text-sm" :class="statusType === 'error' ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'" x-text="status"></p>
            </div>
        </div>
    </main>

    <script>
        function logoEditor() {
            return {
                mode: 'select',
                shapeType: 'rect',
                activeColor: '#0f766e',
                activeSize: 4,
                eraserMode: false,
                saving: false,
                status: 'Ready. Choose Add Text, Shapes, or Draw.',
                statusType: 'ok',
                imageUrl: @json($activeImageUrl),
                imageIndex: @json($activeImageIndex),
                saveUrl: @json(route('logos.saveEdited', $logoRequest)),

                canvasEl: null,
                ctx: null,
                image: null,
                canvasW: 0,
                canvasH: 0,
                dragStart: null,
                dragObjectOffset: null,
                selectedObjectId: null,
                drawingStroke: null,
                pendingShape: null,

                objects: [],
                strokes: [],
                history: [],
                historyIndex: -1,

                init() {
                    this.canvasEl = document.getElementById('logo-canvas');
                    this.ctx = this.canvasEl.getContext('2d');
                    this.bindCanvasEvents();
                    window.addEventListener('keydown', (e) => this.onKeyDown(e));
                    this.loadBaseImage();
                },

                setMode(nextMode) {
                    this.mode = nextMode;
                    if (nextMode !== 'draw') {
                        this.eraserMode = false;
                    }
                    this.setStatus(nextMode === 'text'
                        ? 'Add Text active. Click anywhere on the logo to insert text.'
                        : nextMode === 'shape'
                            ? 'Shapes active. Click and drag to place a shape.'
                            : nextMode === 'draw'
                                ? 'Draw active. Drag on logo to draw. Turn on Eraser to erase.'
                                : 'Select mode active.',
                        'ok');
                },

                setStatus(message, type = 'ok') {
                    this.status = message;
                    this.statusType = type;
                },

                async loadBaseImage() {
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = () => {
                        this.image = img;
                        this.canvasW = img.naturalWidth;
                        this.canvasH = img.naturalHeight;
                        this.canvasEl.width = this.canvasW;
                        this.canvasEl.height = this.canvasH;
                        this.render();
                        this.pushHistory();
                    };
                    img.onerror = () => {
                        this.setStatus('Failed to load logo image for editing.', 'error');
                    };
                    img.src = this.imageUrl;
                },

                bindCanvasEvents() {
                    this.canvasEl.addEventListener('mousedown', (e) => this.onPointerDown(e));
                    this.canvasEl.addEventListener('mousemove', (e) => this.onPointerMove(e));
                    window.addEventListener('mouseup', (e) => this.onPointerUp(e));
                    this.canvasEl.addEventListener('dblclick', (e) => this.onDoubleClick(e));
                },

                canvasPoint(evt) {
                    const r = this.canvasEl.getBoundingClientRect();
                    const sx = this.canvasW / r.width;
                    const sy = this.canvasH / r.height;
                    return {
                        x: (evt.clientX - r.left) * sx,
                        y: (evt.clientY - r.top) * sy,
                    };
                },

                onPointerDown(evt) {
                    if (!this.image) return;
                    const p = this.canvasPoint(evt);

                    if (this.mode === 'text') {
                        const text = prompt('Text:', 'New Text');
                        if (text && text.trim() !== '') {
                            this.objects.push({
                                id: crypto.randomUUID(),
                                type: 'text',
                                x: p.x,
                                y: p.y,
                                text: text.trim(),
                                size: 36,
                                color: this.activeColor,
                            });
                            this.selectedObjectId = this.objects[this.objects.length - 1].id;
                            this.render();
                            this.pushHistory();
                            this.mode = 'select';
                        }
                        return;
                    }

                    if (this.mode === 'draw') {
                        this.drawingStroke = {
                            type: this.eraserMode ? 'eraser' : 'pen',
                            color: this.activeColor,
                            size: this.activeSize,
                            points: [p],
                        };
                        this.strokes.push(this.drawingStroke);
                        this.render();
                        return;
                    }

                    if (this.mode === 'shape') {
                        this.pendingShape = {
                            id: crypto.randomUUID(),
                            type: this.shapeType,
                            x: p.x,
                            y: p.y,
                            w: 1,
                            h: 1,
                            x2: p.x,
                            y2: p.y,
                            color: this.activeColor,
                            size: this.activeSize,
                        };
                        this.objects.push(this.pendingShape);
                        this.selectedObjectId = this.pendingShape.id;
                        this.dragStart = p;
                        this.render();
                        return;
                    }

                    const hit = this.hitObject(p);
                    if (hit) {
                        this.selectedObjectId = hit.id;
                        this.dragStart = p;
                        this.dragObjectOffset = { x: p.x - hit.x, y: p.y - hit.y };
                    } else {
                        this.selectedObjectId = null;
                    }
                    this.render();
                },

                onPointerMove(evt) {
                    if (!this.image) return;
                    const p = this.canvasPoint(evt);

                    if (this.mode === 'draw' && this.drawingStroke) {
                        this.drawingStroke.points.push(p);
                        this.render();
                        return;
                    }

                    if (this.mode === 'shape' && this.pendingShape && this.dragStart) {
                        if (this.pendingShape.type === 'line') {
                            this.pendingShape.x = this.dragStart.x;
                            this.pendingShape.y = this.dragStart.y;
                            this.pendingShape.x2 = p.x;
                            this.pendingShape.y2 = p.y;
                            this.pendingShape.w = Math.max(1, Math.abs(p.x - this.dragStart.x));
                            this.pendingShape.h = Math.max(1, Math.abs(p.y - this.dragStart.y));
                        } else {
                            const left = Math.min(this.dragStart.x, p.x);
                            const top = Math.min(this.dragStart.y, p.y);
                            this.pendingShape.x = left;
                            this.pendingShape.y = top;
                            this.pendingShape.w = Math.max(1, Math.abs(p.x - this.dragStart.x));
                            this.pendingShape.h = Math.max(1, Math.abs(p.y - this.dragStart.y));
                            this.pendingShape.x2 = p.x;
                            this.pendingShape.y2 = p.y;
                        }
                        this.render();
                        return;
                    }

                    if (this.mode === 'select' && this.dragStart && this.selectedObjectId) {
                        const obj = this.objects.find((o) => o.id === this.selectedObjectId);
                        if (!obj) return;
                        if (obj.type === 'text' || obj.type === 'rect' || obj.type === 'circle') {
                            obj.x = p.x - this.dragObjectOffset.x;
                            obj.y = p.y - this.dragObjectOffset.y;
                        } else if (obj.type === 'line') {
                            const dx = p.x - this.dragStart.x;
                            const dy = p.y - this.dragStart.y;
                            obj.x += dx;
                            obj.y += dy;
                            obj.x2 += dx;
                            obj.y2 += dy;
                            this.dragStart = p;
                        }
                        this.render();
                    }
                },

                onPointerUp() {
                    const changed = !!this.drawingStroke || !!this.pendingShape || !!this.dragStart;
                    this.drawingStroke = null;
                    if (this.pendingShape) {
                        this.mode = 'select';
                    }
                    this.pendingShape = null;
                    this.dragStart = null;
                    this.dragObjectOffset = null;
                    if (changed) {
                        this.pushHistory();
                    }
                },

                onDoubleClick(evt) {
                    const p = this.canvasPoint(evt);
                    const hit = this.hitObject(p);
                    if (!hit || hit.type !== 'text') return;
                    const next = prompt('Edit text:', hit.text || '');
                    if (next === null) return;
                    hit.text = next;
                    this.render();
                    this.pushHistory();
                },

                hitObject(p) {
                    for (let i = this.objects.length - 1; i >= 0; i -= 1) {
                        const o = this.objects[i];
                        if (o.type === 'text') {
                            this.ctx.font = `${o.size || 36}px Arial`;
                            const w = this.ctx.measureText(o.text || '').width;
                            const h = o.size || 36;
                            if (p.x >= o.x && p.x <= o.x + w && p.y >= o.y - h && p.y <= o.y) return o;
                        } else if (o.type === 'rect' || o.type === 'circle') {
                            if (p.x >= o.x && p.x <= o.x + o.w && p.y >= o.y && p.y <= o.y + o.h) return o;
                        } else if (o.type === 'line') {
                            const minX = Math.min(o.x, o.x2) - 8;
                            const maxX = Math.max(o.x, o.x2) + 8;
                            const minY = Math.min(o.y, o.y2) - 8;
                            const maxY = Math.max(o.y, o.y2) + 8;
                            if (p.x >= minX && p.x <= maxX && p.y >= minY && p.y <= maxY) return o;
                        }
                    }
                    return null;
                },

                drawStrokes() {
                    for (const stroke of this.strokes) {
                        if (!stroke.points || stroke.points.length === 0) continue;
                        this.ctx.save();
                        this.ctx.globalCompositeOperation = stroke.type === 'eraser' ? 'destination-out' : 'source-over';
                        this.ctx.strokeStyle = stroke.color || '#000000';
                        this.ctx.lineWidth = stroke.size || 4;
                        this.ctx.lineCap = 'round';
                        this.ctx.lineJoin = 'round';
                        this.ctx.beginPath();
                        this.ctx.moveTo(stroke.points[0].x, stroke.points[0].y);
                        for (let i = 1; i < stroke.points.length; i += 1) {
                            this.ctx.lineTo(stroke.points[i].x, stroke.points[i].y);
                        }
                        this.ctx.stroke();
                        this.ctx.restore();
                    }
                },

                drawObjects() {
                    for (const o of this.objects) {
                        this.ctx.save();
                        this.ctx.strokeStyle = o.color || '#000000';
                        this.ctx.fillStyle = o.color || '#000000';
                        this.ctx.lineWidth = o.size || 4;

                        if (o.type === 'text') {
                            this.ctx.font = `${o.size || 36}px Arial`;
                            this.ctx.fillText(o.text || '', o.x, o.y);
                        } else if (o.type === 'rect') {
                            this.ctx.strokeRect(o.x, o.y, o.w, o.h);
                        } else if (o.type === 'circle') {
                            this.ctx.beginPath();
                            this.ctx.ellipse(o.x + (o.w / 2), o.y + (o.h / 2), Math.max(1, o.w / 2), Math.max(1, o.h / 2), 0, 0, Math.PI * 2);
                            this.ctx.stroke();
                        } else if (o.type === 'line') {
                            this.ctx.beginPath();
                            this.ctx.moveTo(o.x, o.y);
                            this.ctx.lineTo(o.x2, o.y2);
                            this.ctx.stroke();
                        }

                        if (this.selectedObjectId === o.id && this.mode !== 'draw') {
                            const box = this.objectBounds(o);
                            this.ctx.setLineDash([8, 6]);
                            this.ctx.lineWidth = 2;
                            this.ctx.strokeStyle = '#10b981';
                            this.ctx.strokeRect(box.x, box.y, box.w, box.h);
                        }
                        this.ctx.restore();
                    }
                },

                objectBounds(o) {
                    if (o.type === 'text') {
                        this.ctx.font = `${o.size || 36}px Arial`;
                        const w = this.ctx.measureText(o.text || '').width;
                        const h = o.size || 36;
                        return { x: o.x, y: o.y - h, w, h };
                    }
                    if (o.type === 'line') {
                        return {
                            x: Math.min(o.x, o.x2),
                            y: Math.min(o.y, o.y2),
                            w: Math.abs(o.x2 - o.x),
                            h: Math.abs(o.y2 - o.y),
                        };
                    }
                    return { x: o.x, y: o.y, w: o.w, h: o.h };
                },

                render() {
                    if (!this.ctx || !this.image) return;
                    this.ctx.clearRect(0, 0, this.canvasW, this.canvasH);
                    this.ctx.drawImage(this.image, 0, 0, this.canvasW, this.canvasH);
                    this.drawStrokes();
                    this.drawObjects();
                },

                serializeState() {
                    return JSON.stringify({ objects: this.objects, strokes: this.strokes, selectedObjectId: this.selectedObjectId });
                },

                restoreState(payload) {
                    const parsed = JSON.parse(payload);
                    this.objects = parsed.objects || [];
                    this.strokes = parsed.strokes || [];
                    this.selectedObjectId = parsed.selectedObjectId || null;
                    this.render();
                },

                pushHistory() {
                    const state = this.serializeState();
                    if (this.historyIndex >= 0 && this.history[this.historyIndex] === state) return;
                    this.history = this.history.slice(0, this.historyIndex + 1);
                    this.history.push(state);
                    this.historyIndex = this.history.length - 1;
                },

                undo() {
                    if (this.historyIndex <= 0) return;
                    this.historyIndex -= 1;
                    this.restoreState(this.history[this.historyIndex]);
                },

                redo() {
                    if (this.historyIndex >= this.history.length - 1) return;
                    this.historyIndex += 1;
                    this.restoreState(this.history[this.historyIndex]);
                },

                resetEdits() {
                    this.objects = [];
                    this.strokes = [];
                    this.selectedObjectId = null;
                    this.render();
                    this.pushHistory();
                    this.setStatus('Edits reset.', 'ok');
                },

                onKeyDown(e) {
                    if (e.key === 'Delete' || e.key === 'Backspace') {
                        if (!this.selectedObjectId) return;
                        const idx = this.objects.findIndex((o) => o.id === this.selectedObjectId);
                        if (idx !== -1) {
                            this.objects.splice(idx, 1);
                            this.selectedObjectId = null;
                            this.render();
                            this.pushHistory();
                        }
                    }
                },

                async saveEditedLogo() {
                    if (!this.image) return;
                    this.saving = true;
                    this.setStatus('Saving edited logo...', 'ok');
                    try {
                        const imageData = this.canvasEl.toDataURL('image/png');
                        const response = await fetch(this.saveUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            },
                            body: JSON.stringify({ image_data: imageData, image_index: this.imageIndex }),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.setStatus(data.error || 'Failed to save edited logo.', 'error');
                            return;
                        }
                        this.setStatus('Edited logo saved successfully.', 'ok');
                    } catch (err) {
                        this.setStatus('Save failed. If this is a remote image without CORS, choose a stored image and try again.', 'error');
                    } finally {
                        this.saving = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
