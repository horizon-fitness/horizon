<!-- Global Glassmorphism Image Viewer -->
<style>
    #global-image-viewer {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(10, 9, 13, 0.4);
        backdrop-filter: blur(40px) saturate(180%);
        -webkit-backdrop-filter: blur(40px) saturate(180%);
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
        max-width: 90%;
        max-height: 90%;
        position: relative;
        transform: scale(0.9);
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    #global-image-viewer.active .viewer-content {
        transform: scale(1);
    }
    .viewer-img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 24px;
        box-shadow: 0 40px 100px rgba(0,0,0,0.5);
        border: 1px solid rgba(255,255,255,0.1);
    }
    .viewer-close {
        position: absolute;
        top: -20px;
        right: -20px;
        width: 44px;
        height: 44px;
        background: white;
        color: black;
        border-radius: 50%;
        display: flex;
        items-center: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        transition: all 0.2s;
    }
    .viewer-close:hover {
        transform: rotate(90deg) scale(1.1);
    }
</style>

<div id="global-image-viewer" onclick="closeImageViewer()">
    <div class="viewer-content" onclick="event.stopPropagation()">
        <button class="viewer-close" onclick="closeImageViewer()">
            <span class="material-symbols-outlined">close</span>
        </button>
        <img id="viewer-main-img" src="" alt="View Large" class="viewer-img">
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
