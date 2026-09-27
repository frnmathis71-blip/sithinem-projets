<form method="post" action="{{ route('cookies.save') }}" class="inline-form">@csrf
<button class="button secondary" name="choice" value="rejected">Refuser les cookies facultatifs</button>
<button class="button secondary" name="choice" value="accepted">Accepter les cookies facultatifs</button>
</form>
