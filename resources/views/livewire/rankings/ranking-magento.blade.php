<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div>
    <div>
        <div class="grid grid-cols-1 sm:hidden">
            <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
            <select aria-label="Select a tab"
                class="col-start-1 row-start-1 w-full appearance-none rounded-md bg-white py-2 pr-8 pl-3 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600">
                <option>France</option>
                <option>Company</option>
                <option selected>Team Members</option>
                <option>Billing</option>
            </select>
            <svg class="pointer-events-none col-start-1 row-start-1 mr-2 size-5 self-center justify-self-end fill-gray-500"
                viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
                <path fill-rule="evenodd"
                    d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                    clip-rule="evenodd" />
            </svg>
        </div>
        <div class="hidden sm:block">
            <nav class="flex space-x-4" aria-label="Tabs">
                <!-- Actif: "bg-gray-100 text-gray-700", Défaut: "text-gray-500 hover:text-gray-700" -->

                <a href="#" class="rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                    France
                </a>

                <a href="#" class="rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                    Belgique
                </a>

                <a href="#" class="rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700"
                    aria-current="page">
                    Espagne
                </a>

                <a href="#" class="rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                    Italie
                </a>

                <a href="#" class="rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:text-gray-700">
                    Allemagne
                </a>
            </nav>

        </div>
    </div>





    <div class="px-4 sm:px-6 lg:px-8">
  <div class="sm:flex sm:items-center">
    <div class="sm:flex-auto">
      <h1 class="text-base font-semibold text-gray-900">Users</h1>
      <p class="mt-2 text-sm text-gray-700">A list of all the users in your account including their name, title, email and role.</p>
    </div>
    <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
      <button type="button" class="block rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Add user</button>
    </div>
  </div>
  <div class="mt-8 flow-root">
    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
      <div class="inline-block min-w-full py-2 align-middle">
        <table class="min-w-full divide-y divide-gray-300">
          <thead>
            <tr>
              <th scope="col" class="py-3.5 pr-3 pl-4 text-left text-sm font-semibold text-gray-900 sm:pl-6 lg:pl-8">Name</th>
              <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Title</th>
              <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Email</th>
              <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Role</th>
              <th scope="col" class="relative py-3.5 pr-4 pl-3 sm:pr-6 lg:pr-8">
                <span class="sr-only">Edit</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-white">
            <tr>
              <td class="py-4 pr-3 pl-4 text-sm font-medium whitespace-nowrap text-gray-900 sm:pl-6 lg:pl-8">Lindsay Walton</td>
              <td class="px-3 py-4 text-sm whitespace-nowrap text-gray-500">Front-end Developer</td>
              <td class="px-3 py-4 text-sm whitespace-nowrap text-gray-500">lindsay.walton@example.com</td>
              <td class="px-3 py-4 text-sm whitespace-nowrap text-gray-500">Member</td>
              <td class="relative py-4 pr-4 pl-3 text-right text-sm font-medium whitespace-nowrap sm:pr-6 lg:pr-8">
                <a href="#" class="text-indigo-600 hover:text-indigo-900">Edit<span class="sr-only">, Lindsay Walton</span></a>
              </td>
            </tr>

            <!-- More people... -->
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div>