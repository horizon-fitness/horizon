<!-- Global Glassmorphism Image Viewer -->
<style>
    #global-image-viewer {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(10, 9, 13, 0.6);
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
        align-items: center;
        justify-content: center;
        padding: 40px;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    #global-image-viewer.active {
        display: flex;
        opacity: 1;
    }
    .viewer-content {
        width: 100%;
        max-width: 900px;
        max-height: 85vh;
        position: relative;
        transform: scale(0.95);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        background: #14121a;
        border-radius: 28px;
        border: 1px solid rgba(255,255,255,0.1);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 50px 100px -20px rgba(0,0,0,0.7);
    }
    #global-image-viewer.active .viewer-content {
        transform: scale(1);
    }
    .viewer-header {
        padding: 20px 24px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(255,255,255,0.02);
    }
    .viewer-title {
        font-size: 10px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #8c2bee;
    }
    .viewer-body {
        flex: 1;
        overflow: auto;
        padding: 24px;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        background: #0d0c12;
    }
    /* Custom Scrollbar for Viewer */
    .viewer-body::-webkit-scrollbar { width: 6px; }
    .viewer-body::-webkit-scrollbar-track { background: transparent; }
    .viewer-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
    .viewer-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

    .viewer-img {
        max-width: 100%;
        height: auto;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        border: 1px solid rgba(255,255,255,0.05);
    }
    .viewer-close {
        width: 36px;
        height: 36px;
        background: rgba(255,255,255,0.05);
        color: white;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid rgba(255,255,255,0.05);
    }
    .viewer-close:hover {
        background: #ef4444;
        color: white;
        transform: rotate(90deg);
        border-color: #ef4444;
    }
</style>

<div id="global-image-viewer" onclick="closeImageViewer()">
    <div class="viewer-content" onclick="event.stopPropagation()">
        <div class="viewer-header">
            <span class="viewer-title">Document Inspection</span>
            <button class="viewer-close" onclick="closeImageViewer()">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <div class="viewer-body">
            <img id="viewer-main-img" src="" alt="View Large" class="viewer-img">
        </div>
    </div>
</div>

<script>
function openImageViewer(src) {
    if (!src) return;
    const viewer = document.getElementById('global-image-viewer');
    const img = document.getElementById('viewer-main-img');
    img.src = src;
    viewer.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeImageViewer() {
    const viewer = document.getElementById('global-image-viewer');
    viewer.classList.remove('active');
    document.body.style.overflow = '';
}

// Add functionality to all images with 'viewable' class or auto-init
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('img.viewable').forEach(img => {
        img.style.cursor = 'zoom-in';
        img.onclick = () => openImageViewer(img.src);
    });
});
</script>
