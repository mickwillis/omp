{**
 * navbar.tpl
 *
 * Copyright (c) 2003-2009 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Navigation Bar
 *
 *}

<div class="super_navigation">
	<div class="unit size1of2">
		<ul class="flat_list installation_navigation">
			<li><a href="{url page="index"}">{translate key="navigation.home"}</a></li>
			<li><a href="{url page="about"}">{translate key="navigation.about"}</a></li>
		</ul>
	</div>
	{if $isUserLoggedIn}
	<div class="unit size1of2 lastUnit align_right">
		<ul class="flat_list user_navigation">
			<li><a href="{url page="user" op="profile"}">{translate key="user.profile"}</a></li>
			<li><a href="{url page="login" op="signOut"}">{translate key="user.logOut"}</a></li>
		</ul>
	</div>
	{else}
	<div class="unit size1of2 lastUnit align_right">
		<ul class="flat_list user_navigation">
			<li><a href="{url page="login"}">{translate key="navigation.login"}</a></li>
			<li><a href="{url page="user" op="register"}">{translate key="navigation.register"}</a></li>
		</ul>
	</div>
	{/if}{* $isUserLoggedIn *}

</div>	<!-- /super_navigation -->