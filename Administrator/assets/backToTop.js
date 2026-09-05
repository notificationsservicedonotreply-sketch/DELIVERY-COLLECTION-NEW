
window.addEventListener('scroll', function() {

    const btn = document.getElementById('backToTop');

    if (window.scrollY > 300) {
        btn.classList.add('show');
    } else {
        btn.classList.remove('show');
    }

});

function scrollToTop() {

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });

}
