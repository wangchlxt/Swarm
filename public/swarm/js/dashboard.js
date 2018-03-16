/**
 * Perforce Swarm
 *
 * @copyright   2013-2018 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2017.4/1623486
 */
/*global Map*/
swarm.dashboard = {
    reviews: {
        cache: [],
        init: function (table) {
            swarm.dashboard.reviews.load(function(){
                swarm.dashboard.rebuildFilters($('#actionable-reviews-filter-bar'));
                $(table).find('tbody').replaceWith(swarm.dashboard.buildTableBody($('#dashboard-tab .badge')));
                $('#actionable-review-panel').css('height', swarm.dashboard.spaceAvailable());
            });
        },
        load: function (callback) {
            $.ajax('/dashboards/action', {
                dataType: 'json',
                data: {format: 'json'},
                success: function (data) {

                    swarm.dashboard.reviews.cache = $.map(data.reviews,function(k){return k;}).sort(function(a,b){
                        var key1 = a.updated;
                        var key2 = b.updated;
                        return key1 < key2 ? 1 : key1 > key2 ? -1 : 0;
                    });
                    callback();
                 }
            });
        }
    },

    buildTableBody: function(countElement){

        var reviewTableBody = $(document.createElement('tbody'));

        $.each(swarm.dashboard.reviews.cache, function(){
            reviewTableBody.append(swarm.dashboard.filterReview(this));
        });

        // truncate the description
        reviewTableBody.find('td.description').expander({slicePoint: 90});

        // convert times to time-ago
        reviewTableBody.find('.timeago').timeago();

        // Set the countElement value
        if(countElement){
            countElement.text(reviewTableBody.children().length);
        }

        // Put an empty row if there are no reviews
        if ( reviewTableBody.children().length === 0 ){
            if ($('body').hasClass('authenticated')) {
                reviewTableBody.append('<tr id="empty-review" class="empty"><td colspan="8">'
                    + swarm.te("There are no reviews currently visible.")
                    + '</td></tr>');
            } else {
                reviewTableBody.append('<tr id="guest-empty-review" class="empty"><td colspan="8">'
                    + swarm.te("Your dashboard will only be populated once you have logged in, ")
                    + '<a href="/login/" onclick="swarm.user.login(); return false;">' + swarm.te("Log in") + '</a> '
                    + swarm.te("now.") + '</td></tr>');
            }
        }

        return reviewTableBody;
    },

    spaceAvailable: function(){
        var windowHeight = $(window).height();
        return (windowHeight > 640 ? windowHeight : 640)-$('#actionable-review-panel').position().top;
    },

    init: function() {
        // Disable filters unless authenticated
        $('body.authenticated #actionable-reviews-filter-bar .btn-group *').prop('disabled', false);
        // Define a converter for roles to names
        $.views.converters("roles", function(roles) {
            return $.map( roles, function( role ) {
                return ( swarm.dashboard.filters.role.getHeading(role));
            }).join(', ');
        });
        swarm.dashboard.reviews.init($('#actionable-review-panel table'));

        // wire-up reset
        $('#reset-filter-values').on('click',function(key){
            $.each(swarm.dashboard.filters,function(){
                this.clear();
                localStorage.removeItem('swarm.dashboard.filter.'+key);
            });
            $('#actionable-review-panel table tbody').replaceWith(swarm.dashboard.buildTableBody($('#dashboard-tab .badge')));
        });

        // wire up inputs
        $('#actionable-reviews-filter-bar ul.dropdown-menu').on('select click change', 'a', function(){
            var device = $(this).parents('div .btn-group');
            device.find('span.text').text($(this).text());
            var filter=device.data('filter-key');
            swarm.localStorage.set('swarm.dashboard.filter.'+filter,$(this).data('filter-value'));
            $('#actionable-review-panel table tbody').replaceWith(swarm.dashboard.buildTableBody($('#dashboard-tab .badge')));
        });

        // wire-up search filter
        var events = ['input', 'keyup', 'blur'];
        $('#actionable-reviews-filter-bar .search input').on(
            events.map(function(e){ return e + '.dashboard.search'; }).join(' '),
            function(event){
                // apply delayed search
                var filterBar = $('#actionable-reviews-filter-bar');
                clearTimeout(swarm.dashboard.searchTimeout);
                swarm.dashboard.searchTimeout = setTimeout(function(){
                    if ($(event.target).val() !== (filterBar.data('last-search') || '')) {
                        $('#actionable-review-panel table tbody').replaceWith(swarm.dashboard.buildTableBody($('#dashboard-tab .badge')));
                        filterBar.data('last-search', $(event.target).val());
                    }
                }, 500);
            }
        );

        // wire up typeahead
        $('#actionable-reviews-filter-bar input.typeahead').on('change', function(){
            $('#actionable-review-panel table tbody').replaceWith(swarm.dashboard.buildTableBody($('#dashboard-tab .badge')));
        });

        // Allow table to change size if the window is expanded
        $(window).resize(function(){
            $('#actionable-review-panel').css('height', swarm.dashboard.spaceAvailable());
        });

        // Allow local refresh on url modification
        $(window).on('hashchange', swarm.dashboard.openTab);
    },

    rebuildFilters: function(){
        // Rebuild the filter values
        $.each(swarm.dashboard.reviews.cache,function(){
            var review = this;
            $.each(swarm.dashboard.filters,function(){
                this.addValue(review);
            });
        });

        // Rebuild the filter html
        $.each(swarm.dashboard.filters,function(id){
            var filter = this;
            var storedValue = swarm.localStorage.get('swarm.dashboard.filter.' + id );
            // Clear existing values
            if ( filter.dynamic ){
                $('div[data-filter-key="' + id + '"] ul li.dynamic').remove();
                var filterValueList = $('div[data-filter-key="' + id + '"] ul');
                // Add values from the current data set
                $.each(filter.values, function(){
                    filterValueList.append('<li class="dynamic">'
                        + '<a href="#" data-filter-value="'+this+'">'+filter.getHeading(this)+'</a>'
                        + '</li>');
                });
            }

            if ( storedValue ) {
                var device = $('div .btn-group[data-filter-key='+id+']');
                var storedOption = device.find('a[data-filter-value="' + storedValue + '"]');
                if ( storedOption.length > 0 ) {
                    device.find('button span.text').text(storedOption[0].text);
                } else {
                    // The stored item no longer exists in the list so remove it
                    localStorage.removeItem('swarm.dashboard.filter.' + id );
                }
            }
        });

        // Wire up typeahead fields
        $.each($('#actionable-reviews-filter-bar input.typeahead'),function(){
            var filter=$(this).data('filter-key');
            $(this).typeahead(
                {   name: 'author-name',
                    source: swarm.dashboard.filters[filter].values
                });
        });
    },

    filters: {
        "project" : {
            clear : function() {
                swarm.localStorage.set('swarm.dashboard.filter.project','');
                var device = $('div .btn-group[data-filter-key="project"]');
                device.find('button span.text').text(device.find('li a')[0].text);
            },
            dynamic: true,
            values : [],
            projectCache: {},
            addValue : function(review){
                $.each($(review.projects), function() {
                    var project=$(this);
                    var href=project.attr('href');
                    if (href) {
                        var filterValue = href.match(/([^\/]*)\/*$/)[1];
                        if (!swarm.dashboard.filters.project.projectCache[filterValue]) {
                            // Append to a map of project id key and value project name
                            swarm.dashboard.filters.project.projectCache[filterValue] = swarm.dashboard.filters.project.getHeading(filterValue);
                            // Need an array to sort
                            var sorted = [];
                            Object.keys(swarm.dashboard.filters.project.projectCache).forEach(function(key,x1,x2,x3) {
                                sorted.push([swarm.dashboard.filters.project.projectCache[key], key]);
                            });
                            // This will sort the array by value (project name)
                            sorted.sort(swarm.projects.sortByNameIdArray);
                            swarm.dashboard.filters.project.values = [];
                            sorted.forEach(function(value) {
                                swarm.dashboard.filters.project.values.push(value[1]);
                            });
                        }
                    }
                });
            },
            getHeading : function(id){
                return $('.projects-sidebar a[href="/projects/'+id+'"]').text()||id;
            },
            visible:function(review){
                var filterValue = swarm.localStorage.get('swarm.dashboard.filter.project');
                if (filterValue) {
                    var found = false;
                    $.each(filterValue.split(', '), function () {
                        if (!found) {
                            var re = new RegExp('<a href=\"/projects\/' + this + '\/\"', 'g');
                            found = review.projects.match(re) !== null;
                            return;
                        }
                    });
                    return found;
                }
                return true;
            }
        },
        "role" : {
            clear : function() {
                swarm.localStorage.set('swarm.dashboard.filter.role','');
                var device = $('div .btn-group[data-filter-key="role"]');
                device.find('button span.text').text(device.find('li a')[0].text);
            },
            dynamic: true,
            values : [],
            addValue : function(review) {
                $.each(review.roles, function () {
                    var role = this.toString();
                    if (swarm.dashboard.filters.role.values.indexOf(role) === -1) {
                        swarm.dashboard.filters.role.values.push(role);
                    }
                });
            },
            getHeading : function(id){
                var roles = {'reviewer'         : swarm.te('Reviewer'),
                             'required_reviewer': swarm.te('Required reviewer'),
                             'moderator'        : swarm.te('Moderator'),
                             'author'           : swarm.te('Author')
                            };
                return roles[id]||id;
            },
            visible:function(review){
                var filterValue = swarm.localStorage.get('swarm.dashboard.filter.role');
                return filterValue ? review.roles.indexOf(filterValue) !== -1 : true;
            }
        },
        "author" : {
            clear : function() {
                $('#author-filter-value').val('');
            },
            values : [],
            addValue : function(review) {
                if (swarm.dashboard.filters.author.values.indexOf(review.author) === -1) {
                    swarm.dashboard.filters.author.values.push(review.author);
                }
            },
            getHeading : function(id){
                return id;
            },
            visible:function(review) {
                var filterValue = $('#author-filter-value').val();
                return filterValue
                    ? review.author.toLocaleLowerCase().indexOf(filterValue.toLocaleLowerCase()) !== -1
                    : true;
            }
        },
        "search" : {
            clear : function() {
                $('#search-filter-value').val('');
            },
            values : [],
            addValue : function() {
                return;
            },
            getHeading : function(id){
                return id;
            },
            visible:function(review) {
                var filterValue = $('#search-filter-value').val();
                return filterValue
                    ? $(review.description).text().toLocaleLowerCase().indexOf(filterValue.toLocaleLowerCase()) !== -1
                    : true;
            }
        }
    },

    filterReview: function(review){

        var visible = true;
        $.each(swarm.dashboard.filters,function(){
            if ( ! this.visible(review)){
                visible = false;
                return;
            }});
        return visible ? $.templates(
            '<tr data-id="{{>id}}" class="state-{{>state}}">'
            + ' <td class="author center">{{:authorAvatar}}</td>'
            + ' <td class="id"><a href="{{url:"/reviews"}}/{{urlc:id}}">{{>id}}</a></td>'
            + ' <td class="description">{{:description}}</td>'
            + ' <td class="project-branch">{{:projects}}</td>'
            + ' <td class="role">{{roles:roles}}</td>'
            + ' <td class="state center">'
            + '  <a href="{{url:"/reviews"}}/{{urlc:id}}">'
            + '     <i class="swarm-icon icon-review-{{>state}}" title="{{te:stateLabel}}"></i>'
            + '  </a>'
            + ' </td>'
            + ' <td class="votes center">'
            + '  <a href="{{url:"/reviews"}}/{{urlc:id}}">'
            + '   <span class="badge {{if !upVotes.length && !downVotes.length}}muted{{/if}}">'
            + '    {{>upVotes.length}} / {{>downVotes.length}}'
            + '   </span>'
            + '  </a>'
            + ' </td>'
            + ' <td class="updated"><span class="timeago" title="{{>updateDate}}"></span></td>'
            + '</tr>'
        ).render(review) : "";
    },

    openTab: function(event){
        var hash = $(location.hash).attr('id');
        var tab  = 'dashboard-tab';
        if ($('body').hasClass('authenticated')) {
            if ('activity' === hash || swarm.localStorage.get('swarm.home.pane') === 'activity-tab') {
                tab = 'activity-tab';
            }
        } else {
            if ('activity' === hash) {
                tab = 'activity-tab';
            }
        }
        $("#"+tab).click();
    }
};