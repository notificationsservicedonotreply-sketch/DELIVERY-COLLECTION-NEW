document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('attachmentModal');
    if (!modal) return;
    const image = document.getElementById('attachmentPreviewImage');
    const title = document.getElementById('attachmentModalTitle');
    const level = document.getElementById('imageZoomLevel');
    const previous = document.getElementById('previousImage');
    const next = document.getElementById('nextImage');
    const imageStage = document.getElementById('attachmentImageStage');
    let images = [];
    let imageIndex = 0;
    let zoom = 1;
    let translateX = 0, translateY = 0;
    let currentFitZoom = 1;

    const calculateFitZoom = () => {
        if (!image.complete || !image.naturalWidth) return 1;
        const stageRect = imageStage.getBoundingClientRect();
        const padding = 20;
        const stageWidth = stageRect.width - padding;
        const stageHeight = stageRect.height - padding;
        const imageWidth = image.naturalWidth;
        const imageHeight = image.naturalHeight;
        if (imageWidth === 0 || imageHeight === 0) return 1;
        const widthRatio = stageWidth / imageWidth;
        const heightRatio = stageHeight / imageHeight;
        return Math.min(widthRatio, heightRatio);
    };

    const setZoom = (newZoom, animate = true) => {
        zoom = Math.min(3, Math.max(0.1, newZoom));
        if (Math.abs(zoom - currentFitZoom) < 0.01) {
            translateX = 0;
            translateY = 0;
        }
        image.style.transition = animate ? 'transform 0.2s ease' : 'none';
        image.style.transform = `scale(${zoom}) translate(${translateX}px, ${translateY}px)`;
        level.textContent = `${Math.round(zoom * 100)}%`;
    };

    const resetImage = (animate = true) => {
        currentFitZoom = calculateFitZoom();
        zoom = currentFitZoom;
        translateX = 0;
        translateY = 0;
        image.style.transition = animate ? 'transform 0.3s ease' : 'none';
        image.style.transform = `scale(${zoom})`;
        level.textContent = `${Math.round(zoom * 100)}%`;
    };

    const close = () => {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        setTimeout(() => {
            image.src = '';
            images = [];
            imageIndex = 0;
        }, 300);
    };

    const showImage = () => {
        if (!images || images.length === 0) {
            console.error('No images available');
            return;
        }
        if (imageIndex < 0 || imageIndex >= images.length) {
            imageIndex = 0;
        }
        const selected = images[imageIndex];
        if (!selected) {
            console.error('No image selected at index', imageIndex);
            return;
        }
        previous.disabled = imageIndex === 0;
        next.disabled = imageIndex === images.length - 1;
        image.src = selected.url;
        image.alt = selected.name || 'Attachment preview';
        title.textContent = selected.name || 'Image preview';
        if (image.complete) {
            resetImage(true);
        } else {
            image.onload = function() {
                resetImage(true);
                image.onload = null;
            };
            image.onerror = function() {
                console.error('Failed to load image');
                image.onerror = null;
            };
        }
    };

    document.querySelectorAll('.view-attachment').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const imagesData = this.getAttribute('data-images');
            if (!imagesData || imagesData === '[]' || imagesData === '') {
                alert('No images available for this entry.');
                return;
            }
            try {
                const parsedImages = JSON.parse(imagesData);
                if (!Array.isArray(parsedImages) || parsedImages.length === 0) {
                    alert('No images available for this entry.');
                    return;
                }
                images = parsedImages;
                imageIndex = 0;
                modal.classList.add('active');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                showImage();
            } catch (e) {
                console.error('Failed to parse images data:', e);
                alert('Error loading images. Please try again.');
            }
        });
    });

    previous.addEventListener('click', function(e) {
        e.stopPropagation();
        if (imageIndex > 0) { imageIndex--; showImage(); }
    });

    next.addEventListener('click', function(e) {
        e.stopPropagation();
        if (imageIndex < images.length - 1) { imageIndex++; showImage(); }
    });

    document.getElementById('zoomInImage').addEventListener('click', function(e) {
        e.stopPropagation();
        setZoom(zoom + 0.25);
    });

    document.getElementById('zoomOutImage').addEventListener('click', function(e) {
        e.stopPropagation();
        setZoom(zoom - 0.25);
    });

    document.getElementById('closeAttachmentModal').addEventListener('click', function(e) {
        e.stopPropagation();
        close();
    });

    modal.addEventListener('click', function(event) {
        if (event.target === modal) close();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) close();
    });

    document.addEventListener('keydown', function(event) {
        if (!modal.classList.contains('active')) return;
        if (event.key === 'ArrowLeft' && !previous.disabled) previous.click();
        else if (event.key === 'ArrowRight' && !next.disabled) next.click();
    });

    imageStage.addEventListener('wheel', function(e) {
        if (!modal.classList.contains('active')) return;
        e.preventDefault();
        e.stopPropagation();
        const delta = e.deltaY > 0 ? -0.1 : 0.1;
        setZoom(zoom + delta);
    }, { passive: false });

    image.addEventListener('dblclick', function(e) {
        e.preventDefault();
        resetImage(true);
    });

    let resizeTimeout;
    window.addEventListener('resize', function() {
        if (modal.classList.contains('active')) {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => resetImage(true), 200);
        }
    });
});
