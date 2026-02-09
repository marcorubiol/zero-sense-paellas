(function() {
    function initLightbox() {
        if (document.getElementById('zs-lightbox')) return;
        var lb = document.createElement('div');
        lb.id = 'zs-lightbox';
        lb.className = 'zs-lightbox';
        lb.innerHTML =
            '<div class="zs-lightbox-overlay"></div>' +
            '<div class="zs-lightbox-content">' +
                '<button type="button" class="zs-lightbox-close">&times;</button>' +
                '<div class="zs-lightbox-body"></div>' +
            '</div>';
        document.body.appendChild(lb);
    }

    function openLightbox(url, type) {
        initLightbox();
        var body = document.querySelector('#zs-lightbox .zs-lightbox-body');
        body.innerHTML = '';
        if (type === 'video') {
            body.innerHTML = '<video src="' + url + '" controls autoplay style="max-width:100%;max-height:80vh;"></video>';
        } else {
            body.innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:80vh;">';
        }
        document.getElementById('zs-lightbox').classList.add('active');
    }

    function closeLightbox() {
        var lb = document.getElementById('zs-lightbox');
        if (!lb) return;
        var videos = lb.querySelectorAll('video');
        for (var i = 0; i < videos.length; i++) videos[i].pause();
        lb.classList.remove('active');
    }

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.zs-lightbox-trigger');
        if (trigger) {
            e.preventDefault();
            openLightbox(trigger.getAttribute('href'), trigger.getAttribute('data-type') || 'image');
            return;
        }
        if (e.target.closest('.zs-lightbox-close') || e.target.closest('.zs-lightbox-overlay')) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLightbox();
    });
})();
