<!-- Global Glassmorphism Image Viewer -->
<style>
    #global-image-viewer {
        display: none;
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        left: var(--sidebar-width, 0) !important;
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 40px;
        opacity: 0;
        transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        pointer-events: none;
    }
    #imageBackdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        z-index: -1;
        pointer-events: auto;
    }
    #global-image-viewer.active {
        display: flex;
        opacity: 1;
        pointer-events: auto !important;
    }
    .viewer-content {
        width: fit-content;
        min-width: 450px;
        max-width: 95vw;
        max-height: 90vh;
        position: relative;
        transform: scale(0.98);
        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        background: var(--card-bg, #ffffff);
        border-radius: 40px;
        border: 1px solid var(--border-subtle, rgba(0,0,0,0.05));
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 40px 100px -20px rgba(0,0,0,0.2);
    }
    #global-image-viewer.active .viewer-content {
        transform: scale(1);
    }
    .viewer-header {
        padding: 24px 32px;
        border-bottom: 1px solid var(--border-subtle, rgba(0,0,0,0.05));
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(150,150,150,0.05);
    }
    .viewer-title-group {
        display: flex;
        flex-direction: column;
    }
    .viewer-main-title {
        font-size: 9px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.2em;
        color: var(--primary);
        margin-bottom: 4px;
    }
    .viewer-subtitle {
        font-size: 16px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -0.02em;
        color: var(--text-main, #0f172a);
        font-style: italic;
    }
    .viewer-body {
        flex: 1;
        overflow-y: auto;
        padding: 40px;
        display: flex;
        flex-direction: column;
        align-items: center;
        background: var(--background, #f8fafc);
        overscroll-behavior: contain;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE 10+ */
    }
    
    /* Hide scrollbar for Chrome, Safari and Opera */
    .viewer-body::-webkit-scrollbar {
        display: none;
    }

    .viewer-inner-content {
        width: 100%;
        max-width: 100%;
        margin-top: auto;
        margin-bottom: auto; /* Centering with scrollable support */
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 40px;
        padding-bottom: 60px; /* Generous bottom margin */
    }

    .viewer-img {
        max-width: 100%;
        max-height: 80vh; /* Keep label in view by default if possible */
        width: auto;
        height: auto;
        border-radius: 24px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        border: 1px solid rgba(0,0,0,0.05);
        transition: transform 0.3s;
        object-fit: contain;
    }

    .viewer-footer-label {
        width: fit-content;
        min-width: 300px;
    }

    .viewer-action-btn {
        width: 44px;
        height: 44px;
        background: rgba(150,150,150,0.1);
        color: var(--text-main, #475569);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        border: 1px solid var(--border-subtle, rgba(0,0,0,0.05));
        text-decoration: none;
    }
    .viewer-action-btn:hover {
        background: rgba(150,150,150,0.2);
        color: var(--text-main, #0f172a);
    }
    .viewer-close:hover {
        background: #ef4444;
        color: white;
        border-color: #ef4444;
    }
    .viewer-pdf {
        width: 80vw;
        max-width: 1200px; /* Give PDF room to breathe */
        height: 80vh;
        border-radius: 24px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        border: 1px solid var(--border-subtle, rgba(0,0,0,0.05));
        background: var(--card-bg, #e2e8f0); /* Modern dark background for PDF frame edge */
    }
    .hidden {
        display: none !important;
    }
</style>

<div id="global-image-viewer" onclick="closeImageViewer()">
    <div id="imageBackdrop"></div>
    <div class="viewer-content shadow-2xl" onclick="event.stopPropagation()">
        <div class="viewer-header">
            <div class="viewer-title-group">
                <span class="viewer-main-title">Document Verification</span>
                <span id="viewer-subtitle" class="viewer-subtitle">Inspection View</span>
            </div>
            <div class="flex items-center gap-3">
                <a id="viewer-open-tab" href="#" target="_blank" class="viewer-action-btn hidden" title="Open in new tab">
                    <span class="material-symbols-outlined text-xl">open_in_new</span>
                </a>
                <button class="viewer-action-btn viewer-close" onclick="closeImageViewer()" title="Close viewer">
                    <span class="material-symbols-outlined text-xl font-bold">close</span>
                </button>
            </div>
        </div>
        <div class="viewer-body">
            <div class="viewer-inner-content">
                <img id="viewer-main-img" src="" alt="View Large" class="viewer-img hidden">
                <iframe id="viewer-main-pdf" src="" class="viewer-pdf hidden" title="PDF Document"></iframe>
                <div id="viewer-footer-label" class="viewer-footer-label bg-primary/5 px-8 py-4 rounded-3xl border border-primary/20 backdrop-blur-xl opacity-0 transition-opacity duration-500 shadow-xl shadow-primary/5">
                    <p class="text-[10px] font-black uppercase text-primary tracking-[0.2em] mb-1.5 text-center italic">Authenticated Document</p>
                    <p id="viewer-document-name" class="text-sm font-black italic uppercase text-[--text-main] text-center tracking-tight">Document Name</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openImageViewer(src, title = '') {
    if (!src) return;
    const viewer = document.getElementById('global-image-viewer');
    const img = document.getElementById('viewer-main-img');
    const pdf = document.getElementById('viewer-main-pdf');
    const newTabBtn = document.getElementById('viewer-open-tab');
    const subtitle = document.getElementById('viewer-subtitle');
    const footerLabel = document.getElementById('viewer-footer-label');
    const docName = document.getElementById('viewer-document-name');
    
    subtitle.textContent = title || 'Inspection View';
    docName.textContent = title || 'Document Inspection';
    
    // Handle opening in a new tab safely (especially for data: URIs blocked by modern browsers)
    newTabBtn.onclick = function(e) {
        e.preventDefault();
        if (src.startsWith('data:')) {
            // Convert data URI to Blob and generate a safe Object URL
            fetch(src)
                .then(res => res.blob())
                .then(blob => {
                    const blobUrl = URL.createObjectURL(blob);
                    window.open(blobUrl, '_blank');
                    // Revoke after a minute to prevent memory leaks
                    setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
                })
                .catch(() => {
                    // Fallback to downloading if fetch fails
                    const a = document.createElement('a');
                    a.href = src;
                    a.download = 'document_' + Date.now();
                    a.click();
                });
        } else {
            window.open(src, '_blank');
        }
    };
    newTabBtn.classList.remove('hidden');
    
    if (src.toLowerCase().endsWith('.pdf') || src.toLowerCase().startsWith('data:application/pdf')) {
        img.classList.add('hidden');
        img.src = '';
        pdf.src = src;
        pdf.classList.remove('hidden');
    } else {
        pdf.classList.add('hidden');
        pdf.src = '';
        img.src = src;
        img.classList.remove('hidden');
    }
    
    viewer.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Fade in footer label after a slight delay
    setTimeout(() => {
        footerLabel.classList.replace('opacity-0', 'opacity-100');
    }, 300);
}

function closeImageViewer() {
    const viewer = document.getElementById('global-image-viewer');
    const img = document.getElementById('viewer-main-img');
    const pdf = document.getElementById('viewer-main-pdf');
    const footerLabel = document.getElementById('viewer-footer-label');
    
    footerLabel.classList.replace('opacity-100', 'opacity-0');
    viewer.classList.remove('active');
    document.body.style.overflow = '';
    
    setTimeout(() => {
        img.src = '';
        pdf.src = '';
    }, 300);
}

// Global click delegation for image popups
document.addEventListener('click', function(e) {
    let target = e.target.closest('.modal-img-preview');
    if (target && target.dataset.src) {
        openImageViewer(target.dataset.src, target.dataset.title);
        return;
    }

    if (e.target.tagName === 'IMG' && e.target.classList.contains('viewable')) {
        openImageViewer(e.target.src, e.target.alt);
    }
});
</script>
