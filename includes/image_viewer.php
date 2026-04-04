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
        background: rgba(10, 9, 13, 0.75);
        backdrop-filter: blur(25px) saturate(200%);
        -webkit-backdrop-filter: blur(25px) saturate(200%);
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
        background: #14121a;
        border-radius: 40px;
        border: 1px solid rgba(255,255,255,0.1);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 60px 120px -30px rgba(0,0,0,0.8);
    }
    #global-image-viewer.active .viewer-content {
        transform: scale(1);
    }
    .viewer-header {
        padding: 24px 32px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(255,255,255,0.03);
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
        color: white;
        font-style: italic;
    }
    .viewer-body {
        flex: 1;
        overflow-y: auto;
        padding: 40px;
        display: flex;
        flex-direction: column;
        align-items: center;
        background: #0d0c12;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: var(--primary) transparent;
    }
    
    /* Elegant Minimalist Scrollbar (Webkit) */
    .viewer-body::-webkit-scrollbar {
        width: 8px;
    }
    .viewer-body::-webkit-scrollbar-track {
        background: transparent;
    }
    .viewer-body::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.05);
        border: 2px solid #0d0c12;
        border-radius: 10px;
    }
    .viewer-body::-webkit-scrollbar-thumb:hover {
        background: var(--primary);
        border: 2px solid #0d0c12;
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
        box-shadow: 0 40px 80px rgba(0,0,0,0.5);
        border: 1px solid rgba(255,255,255,0.1);
        transition: transform 0.3s;
        object-fit: contain;
    }

    .viewer-footer-label {
        width: fit-content;
        min-width: 300px;
    }

    .viewer-close {
        width: 44px;
        height: 44px;
        background: rgba(255,255,255,0.05);
        color: white;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .viewer-close:hover {
        background: #ef4444;
        color: white;
        transform: rotate(90deg);
        border-color: #ef4444;
        box-shadow: 0 0 20px rgba(239, 68, 68, 0.4);
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
            <button class="viewer-close" onclick="closeImageViewer()">
                <span class="material-symbols-outlined text-xl font-bold">close</span>
            </button>
        </div>
        <div class="viewer-body">
            <div class="viewer-inner-content">
                <img id="viewer-main-img" src="" alt="View Large" class="viewer-img">
                <div id="viewer-footer-label" class="viewer-footer-label bg-primary/5 px-8 py-4 rounded-3xl border border-primary/20 backdrop-blur-xl opacity-0 transition-opacity duration-500 shadow-xl shadow-primary/5">
                    <p class="text-[10px] font-black uppercase text-primary tracking-[0.2em] mb-1.5 text-center italic">Authenticated Document</p>
                    <p id="viewer-document-name" class="text-sm font-black italic uppercase text-white text-center tracking-tight">Document Name</p>
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
    const subtitle = document.getElementById('viewer-subtitle');
    const footerLabel = document.getElementById('viewer-footer-label');
    const docName = document.getElementById('viewer-document-name');
    
    img.src = src;
    subtitle.textContent = title || 'Inspection View';
    docName.textContent = title || 'Document Inspection';
    
    viewer.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Fade in footer label after a slight delay
    setTimeout(() => {
        footerLabel.classList.replace('opacity-0', 'opacity-100');
    }, 300);
}

function closeImageViewer() {
    const viewer = document.getElementById('global-image-viewer');
    const footerLabel = document.getElementById('viewer-footer-label');
    
    footerLabel.classList.replace('opacity-100', 'opacity-0');
    viewer.classList.remove('active');
    document.body.style.overflow = '';
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
