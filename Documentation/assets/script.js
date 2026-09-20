document.addEventListener('DOMContentLoaded', () => {
    // --- TOC ScrollSpy ---
    const tocLinks = document.querySelectorAll('.toc-link');
    const sections = Array.from(document.querySelectorAll('h2, h3'))
        .filter(h => h.id);

    const observerOptions = {
        rootMargin: '-100px 0px -70% 0px',
        threshold: 0
    };

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                tocLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === '#' + entry.target.id) {
                        link.classList.add('active');
                    }
                });
            }
        });
    }, observerOptions);

    sections.forEach(section => observer.observe(section));

    // --- Search Shortcut ---
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            document.getElementById('global-search').focus();
        }
    });

    // --- Sidebar Toggle for Submenus ---
    const subToggles = document.querySelectorAll('[data-toggle="submenu"]');
    subToggles.forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            // Always prevent default for submenu toggles to act as pure dropdown triggers
            e.preventDefault();
            
            const targetId = toggle.getAttribute('data-target');
            const submenu = document.getElementById(targetId);
            const icon = toggle.querySelector('.toggle-icon');
            
            if (submenu) {
                const isOpen = submenu.classList.toggle('open');
                if (icon) icon.style.transform = isOpen ? 'rotate(90deg)' : '';
            }
        });
    });

    // --- Highlight Active Nav Item ---
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        // Prevent reload/scroll to top if clicking current page
        link.addEventListener('click', (e) => {
            if (link.href === window.location.href && !e.target.closest('.toggle-icon')) {
                e.preventDefault();
            }
        });

        if (link.href === window.location.href) {
            link.classList.add('active');
            // Auto open parent submenu if active
            const parentSub = link.closest('.submenu');
            if (parentSub) {
                parentSub.classList.add('open');
                const trigger = document.querySelector(`[data-target="${parentSub.id}"]`);
                if (trigger) {
                    const icon = trigger.querySelector('.toggle-icon');
                    if (icon) icon.style.transform = 'rotate(90deg)';
                }
            }
        }
    });

    // --- Sidebar Scroll Persistence ---
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        const scrollPos = localStorage.getItem('sidebarScroll');
        if (scrollPos) {
            sidebar.scrollTop = parseInt(scrollPos, 10);
        }
        sidebar.addEventListener('scroll', () => {
            localStorage.setItem('sidebarScroll', sidebar.scrollTop);
        });
    }

    // --- Scroll Active Item Into View ---
    const activeLink = document.querySelector('.nav-link.active');
    if (activeLink) {
        activeLink.scrollIntoView({ block: 'nearest' });
    }

    // --- Search Logic (Dropdown Popover) ---
    const searchInput = document.getElementById('global-search');
    const searchContainer = document.querySelector('.search-container');
    
    // Create dropdown element
    let searchDropdown = document.createElement('div');
    searchDropdown.className = 'search-dropdown';
    if (searchContainer) {
        searchContainer.appendChild(searchDropdown);
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const navLinks = document.querySelectorAll('.nav-link');
            
            if (query === '') {
                searchDropdown.classList.remove('active');
                searchDropdown.innerHTML = '';
                return;
            }

            const results = [];
            navLinks.forEach(link => {
                // Only index actual pages (exclude those with href="#" and no parent group titles)
                const href = link.getAttribute('href');
                if (href && href !== '#' && href !== './index.html' && href !== '../../../index.html') {
                    const text = link.textContent.toLowerCase();
                    if (text.includes(query)) {
                        // Find category (it's the nearest preceding .nav-group-title or parent toggle)
                        let category = '';
                        const parentGroup = link.closest('.submenu');
                        if (parentGroup) {
                            const trigger = document.querySelector(`[data-target="${parentGroup.id}"]`);
                            if (trigger) category = trigger.textContent.trim();
                        }
                        
                        results.push({
                            title: link.textContent.trim(),
                            href: href,
                            category: category
                        });
                    }
                }
            });

            if (results.length > 0) {
                // Build results with the DOM API + textContent (never innerHTML
                // with page-derived strings) so result titles/paths can't be
                // interpreted as markup — avoids any XSS-style injection risk.
                searchDropdown.replaceChildren();
                results.forEach(res => {
                    const item = document.createElement('a');
                    item.className = 'search-result-item';
                    item.setAttribute('href', res.href);

                    const box = document.createElement('div');

                    const title = document.createElement('div');
                    title.className = 'result-title';
                    title.textContent = res.title;
                    box.appendChild(title);

                    if (res.category) {
                        const path = document.createElement('div');
                        path.className = 'result-path';
                        path.textContent = res.category;
                        box.appendChild(path);
                    }

                    item.appendChild(box);
                    searchDropdown.appendChild(item);
                });
                searchDropdown.classList.add('active');
            } else {
                searchDropdown.replaceChildren();
                const empty = document.createElement('div');
                empty.className = 'no-results-popover';
                empty.textContent = 'No results found';
                searchDropdown.appendChild(empty);
                searchDropdown.classList.add('active');
            }
        });

        // Close on click outside
        document.addEventListener('click', (e) => {
            if (!searchContainer.contains(e.target)) {
                searchDropdown.classList.remove('active');
            }
        });

        // Open on focus if query exists
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim() !== '') {
                searchDropdown.classList.add('active');
            }
        });
    }

    // --- Dynamic Page Navigation ---
    const pageNav = document.querySelector('.page-navigation');
    if (pageNav) {
        const allNavLinks = Array.from(document.querySelectorAll('.sidebar-nav .nav-link'))
            .filter(link => {
                const href = link.getAttribute('href');
                return href && href !== '#' && !link.hasAttribute('data-toggle');
            });

        const currentHref = window.location.href.split('#')[0];
        const currentIndex = allNavLinks.findIndex(link => link.href.split('#')[0] === currentHref);

        if (currentIndex !== -1) {
            const prev = allNavLinks[currentIndex - 1];
            const next = allNavLinks[currentIndex + 1];

            let navHtml = '';
            if (prev) {
                navHtml += `
                    <a href="${prev.getAttribute('href')}" class="nav-btn prev">
                        <span class="nav-label">Previous</span>
                        <span class="nav-title">${prev.textContent.trim()}</span>
                    </a>`;
            } else {
                navHtml += '<div></div>'; // Spacer for flexbox
            }

            if (next) {
                navHtml += `
                    <a href="${next.getAttribute('href')}" class="nav-btn next">
                        <span class="nav-label">Next</span>
                        <span class="nav-title">${next.textContent.trim()}</span>
                    </a>`;
            }
            
            // Only update if it's currently empty or user wants a refresh
            pageNav.innerHTML = navHtml;
        }
    }

    // --- Code Block Copy Functionality ---
    const codeBlocks = document.querySelectorAll('pre');
    codeBlocks.forEach(block => {
        const button = document.createElement('button');
        button.className = 'copy-btn';
        button.textContent = 'Copy';
        
        button.addEventListener('click', () => {
            const code = block.innerText.replace('Copy', '').trim();
            navigator.clipboard.writeText(code).then(() => {
                button.textContent = 'Copied!';
                button.classList.add('copied');
                
                setTimeout(() => {
                    button.textContent = 'Copy';
                    button.classList.remove('copied');
                }, 2000);
            });
        });

        block.appendChild(button);
    });

    // --- Tabs Functionality ---
    const tabGroups = document.querySelectorAll('.tabs-container');
    
    tabGroups.forEach(group => {
        const buttons = group.querySelectorAll('.tab-btn');
        const contents = group.querySelectorAll('.tab-content');
        
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                // Remove active class from all buttons and contents in this group
                buttons.forEach(b => b.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked button
                btn.classList.add('active');
                
                // Find and activate corresponding content
                const targetId = btn.getAttribute('data-tab');
                const targetContent = group.querySelector(`#${targetId}`);
                if (targetContent) {
                    targetContent.classList.add('active');
                }
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const hamburger = document.querySelector('.hamburger-btn');
    const sidebar   = document.querySelector('.sidebar');
    const wrapper   = document.querySelector('.page-wrapper');

    if (!hamburger || !sidebar || !wrapper) return;

    hamburger.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        wrapper.classList.toggle('sidebar-open');
    });

    // Optional: close sidebar when clicking outside (on mobile)
    document.addEventListener('click', (e) => {
        if (
            window.innerWidth <= 849 &&
            !sidebar.contains(e.target) &&
            !hamburger.contains(e.target) &&
            sidebar.classList.contains('open')
        ) {
            sidebar.classList.remove('open');
            wrapper.classList.remove('sidebar-open');
        }
    });
});