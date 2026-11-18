/**
 * Four Brothers Expedition — main.js
 *
 * Vanilla, no dependencies, deferred. Handles navigation, scroll reveals,
 * the FAQ accordion and the application form.
 */

(function () {
  'use strict';

  /* ─────────────────────────────────────────────────────────────
     FORM DELIVERY
     The form posts JSON to api/contact.php, which relays the enquiry over
     authenticated SMTP on the host (IONOS). Set FORM_ENDPOINT to '' to fall
     back to composing the application in the visitor's email app instead.
     ───────────────────────────────────────────────────────────── */
  var FORM_ENDPOINT = 'https://forms.thejunglewhisperer.com/contact.php';
  var CONTACT_EMAIL = 'jason@thejunglewhisperer.com';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Signal that JS is active for reveal animations
  document.documentElement.classList.remove('no-js');
  document.documentElement.classList.add('js');

  /* ─── NAVIGATION SCROLL EFFECT ─── */
  var nav = document.getElementById('nav');
  var ticking = false;

  function onScroll() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(function () {
      if (nav) nav.classList.toggle('scrolled', window.scrollY > 50);
      ticking = false;
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  if (nav) nav.classList.toggle('scrolled', window.scrollY > 50);

  /* ─── MOBILE NAVIGATION ─── */
  var navToggle = document.getElementById('nav-toggle');
  var navLinks = document.getElementById('nav-links');

  function setNavOpen(open) {
    if (!navToggle || !navLinks) return;
    navLinks.classList.toggle('open', open);
    navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    navToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    document.body.classList.toggle('nav-open', open);
    if (open) {
      var first = navLinks.querySelector('a');
      if (first) first.focus();
    }
  }

  if (navToggle && navLinks) {
    navToggle.addEventListener('click', function () {
      setNavOpen(!navLinks.classList.contains('open'));
    });

    // Close mobile menu when a link is clicked
    navLinks.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        setNavOpen(false);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navLinks.classList.contains('open')) {
        setNavOpen(false);
        navToggle.focus();
      }
    });

    // Tap outside the open panel to dismiss it
    document.addEventListener('click', function (e) {
      if (!navLinks.classList.contains('open')) return;
      if (navLinks.contains(e.target) || navToggle.contains(e.target)) return;
      setNavOpen(false);
    });
  }

  /* ─── SMOOTH SCROLL FOR ANCHOR LINKS ─── */
  if (!reduceMotion) {
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
      anchor.addEventListener('click', function (e) {
        var targetId = this.getAttribute('href');
        if (!targetId || targetId === '#') return;

        var targetEl = document.querySelector(targetId);
        if (!targetEl) return;

        e.preventDefault();
        var navHeight = nav ? nav.offsetHeight : 0;
        var targetPosition = targetEl.getBoundingClientRect().top + window.pageYOffset - navHeight;

        window.scrollTo({ top: targetPosition, behavior: 'smooth' });

        // keep keyboard focus with the visitor after the jump
        if (!targetEl.hasAttribute('tabindex')) targetEl.setAttribute('tabindex', '-1');
        targetEl.focus({ preventScroll: true });
      });
    });
  }

  /* ─── SCROLL REVEAL ANIMATIONS ─── */
  var revealElements = document.querySelectorAll('.reveal');

  if (!reduceMotion && 'IntersectionObserver' in window) {
    var revealObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            revealObserver.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );

    revealElements.forEach(function (el) {
      revealObserver.observe(el);
    });
  } else {
    // Fallback: no observer support, or the visitor asked for reduced motion
    revealElements.forEach(function (el) {
      el.classList.add('visible');
    });
  }

  /* ─── FAQ ACCORDION ─── */
  var faqItems = Array.prototype.slice.call(document.querySelectorAll('.faq-item'));

  faqItems.forEach(function (item) {
    var question = item.querySelector('.faq-question');
    var answer = item.querySelector('.faq-answer');
    if (!question || !answer) return;

    question.addEventListener('click', function () {
      var willOpen = !item.classList.contains('active');

      faqItems.forEach(function (other) {
        var otherQ = other.querySelector('.faq-question');
        var otherA = other.querySelector('.faq-answer');
        other.classList.remove('active');
        if (otherQ) otherQ.setAttribute('aria-expanded', 'false');
        if (otherA) otherA.hidden = true;
      });

      if (willOpen) {
        item.classList.add('active');
        question.setAttribute('aria-expanded', 'true');
        answer.hidden = false;
      }
    });
  });

  /* ─── APPLICATION FORM ─── */
  var form = document.getElementById('booking-form');

  if (form) {
    var success = document.getElementById('form-success');
    var payload = document.getElementById('form-payload');
    var errorBox = document.getElementById('form-error');
    var submitBtn = document.getElementById('form-submit');
    var copyBtn = document.getElementById('form-copy');
    var submitting = false;
    var loadedAt = Date.now();

    function showError(message) {
      if (!errorBox) return;
      errorBox.textContent = message;
      errorBox.hidden = false;
    }

    function clearError() {
      if (errorBox) errorBox.hidden = true;
    }

    function compose() {
      var data = new FormData(form);
      var lines = [];
      var labels = {
        name: 'Name',
        email: 'Email',
        phone: 'Phone',
        location: 'Based in',
        motivation: 'What draws them to the expedition',
        experience: 'Experience level',
        source: 'Heard about it via',
        notes: 'Anything else'
      };

      data.forEach(function (value, key) {
        if (key === 'website' || !value) return; // honeypot + blank fields
        lines.push((labels[key] || key) + ': ' + value);
      });

      return {
        subject: 'Four Brothers Expedition — application from ' + (data.get('name') || 'website visitor'),
        body: lines.join('\n') + '\n\n— Sent from the Four Brothers Expedition website'
      };
    }

    function revealSuccess(message) {
      Array.prototype.forEach.call(form.children, function (child) {
        child.hidden = true;
        child.style.display = 'none';
      });
      if (success) {
        success.hidden = false;
        success.style.display = 'block';
        var line = success.querySelector('#form-success-text');
        if (line && message) line.textContent = message;
        success.focus();
      }
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (submitting) return;
      clearError();

      // Honeypot: a filled hidden field means a bot. Pretend success, send nothing.
      var trap = form.querySelector('#website');
      if (trap && trap.value) {
        revealSuccess('Thank you. Jason will be in touch personally within 48 hours.');
        return;
      }

      // Validate with a visible message rather than a silent block.
      var firstInvalid = null;
      form.querySelectorAll('input[required], textarea[required]').forEach(function (field) {
        field.removeAttribute('aria-invalid');
        if (!field.checkValidity()) {
          field.setAttribute('aria-invalid', 'true');
          if (!firstInvalid) firstInvalid = field;
        }
      });

      if (firstInvalid) {
        showError('Please complete the highlighted fields — your name, email and what draws you here.');
        firstInvalid.focus();
        return;
      }

      var msg = compose();
      if (payload) payload.value = msg.body;

      var mailto =
        'mailto:' + CONTACT_EMAIL +
        '?subject=' + encodeURIComponent(msg.subject) +
        '&body=' + encodeURIComponent(msg.body);

      if (!FORM_ENDPOINT) {
        // Email-app delivery: always available, nothing stored on this site.
        revealSuccess('Your email app is opening with your application pre-filled. Press send there and it comes straight to Jason, who replies personally within 48 hours.');
        window.location.href = mailto;
        return;
      }

      submitting = true;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';
      }

      var out = {};
      new FormData(form).forEach(function (value, key) { out[key] = value; });
      out.elapsed = Date.now() - loadedAt;

      fetch(FORM_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(out)
      })
        .then(function (response) {
          return response.json()
            .catch(function () { return null; })
            .then(function (data) {
              if (response.ok && data && data.ok) return data;
              var err = new Error((data && data.error) || 'Sending failed (' + response.status + ').');
              err.serverReplied = true;
              err.status = response.status;
              throw err;
            });
        })
        .then(function () {
          revealSuccess('Application received. Jason will be in touch personally within 48 hours.');
        })
        .catch(function (err) {
          submitting = false;
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send My Application →';
          }

          // The server answered, so the message is specific and worth showing.
          if (err && err.serverReplied) {
            if (!errorBox) return;
            errorBox.textContent = err.message + ' ';
            if (err.status === 429 || err.status === 502) {
              var alt = document.createElement('a');
              alt.href = mailto;
              alt.textContent = 'Email Jason directly instead →';
              errorBox.appendChild(alt);
            }
            errorBox.hidden = false;
            return;
          }

          // Network failure: never lose an enquiry — hand it to their email app.
          showError('We could not reach the server, so we are opening your email app instead. Your answers are pre-filled — press send there.');
          window.location.href = mailto;
        });
    });

    if (copyBtn && payload) {
      copyBtn.addEventListener('click', function () {
        payload.hidden = false;
        var done = function () {
          copyBtn.textContent = 'Copied ✓';
          window.setTimeout(function () { copyBtn.textContent = 'Copy my answers'; }, 2500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(payload.value).then(done, function () {
            payload.select();
          });
        } else {
          payload.select();
          try {
            document.execCommand('copy');
            done();
          } catch (err) {
            /* selection is left visible so the visitor can copy manually */
          }
        }
      });
    }
  }

  /* ─── HERO PARALLAX (on the picture wrapper, so the zoom animation still runs) ─── */
  var heroMedia = document.querySelector('.hero-bg picture');

  if (heroMedia && !reduceMotion) {
    var parallaxTicking = false;
    window.addEventListener(
      'scroll',
      function () {
        if (parallaxTicking) return;
        parallaxTicking = true;
        window.requestAnimationFrame(function () {
          heroMedia.style.transform = 'translateY(' + window.pageYOffset * 0.25 + 'px)';
          parallaxTicking = false;
        });
      },
      { passive: true }
    );
  }
})();
